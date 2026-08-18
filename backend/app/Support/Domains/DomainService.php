<?php

namespace App\Support\Domains;

use App\Contracts\DomainProviderInterface;
use App\Events\DomainRegistered;
use App\Events\DomainRenewed;
use App\Events\DomainTransferred;
use App\Events\DomainUpdated;
use App\Models\DnsZone;
use App\Models\Domain;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Features\FeatureFlagService;
use App\Support\Providers\ResolvesTenantProvider;
use App\Support\Ssl\SslProviderException;
use App\Support\Ssl\SslService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Owns the domain lifecycle. OMNEX is the system of record; the selected
 * DomainProviderInterface only performs registry operations. Events are the
 * integration surface (audit, notifications, automation).
 *
 * Search/check/register/transfer accept an explicit provider name; renew and
 * update always use the provider the domain was registered with.
 */
final class DomainService
{
    use ResolvesTenantProvider;

    public function __construct(private DomainProviderRegistry $providers) {}

    protected function providerConfigKey(): string
    {
        return 'omnex.domain.provider';
    }

    protected function providerSettingsKey(): string
    {
        return 'domain_provider';
    }

    /**
     * @return array<int, array{name: string, label: string, configured: bool}>
     */
    public function providers(): array
    {
        return $this->providers->all();
    }

    private function provider(?string $name = null): DomainProviderInterface
    {
        $provider = $this->providers->get($name ?? $this->activeProviderName());

        if (! $provider->isConfigured()) {
            throw new DomainProviderException("The [{$provider->label()}] registrar is not configured.");
        }

        return $provider;
    }

    public function search(string $query, array $tlds = [], ?string $provider = null): array
    {
        if (trim($query) === '') {
            return [];
        }

        return $this->provider($provider)->search($query, $tlds);
    }

    public function check(string $domain, ?string $provider = null): array
    {
        $domain = $this->normalize($domain);

        $result = $this->provider($provider)->checkAvailability($domain);
        $result['managed'] = Domain::withoutTenancy()->where('name', $domain)->exists();

        return $result;
    }

    public function register(string $domain, array $options, User $user, ?string $provider = null): Domain
    {
        $domain = $this->normalize($domain);

        $selected = $this->provider($provider);

        $this->assertRegisterable($domain, $selected);

        $remote = $selected->register($domain, $options);

        $model = $this->persistDomain([
            'name' => $domain,
            'status' => 'active',
            'external_id' => $remote['external_id'] ?? null,
            'registered_at' => $remote['registered_at'] ?? now(),
            'expires_at' => $remote['expires_at'] ?? now()->addYear(),
        ], $selected->name());

        $this->provisionSsl($model);

        DomainRegistered::dispatch($model);

        return $model->load('zone.records', 'certificate');
    }

    public function renew(Domain $domain, int $years): Domain
    {
        $years = max(1, $years);

        $remote = $this->provider($domain->provider)->renew($domain->name, $years);

        $domain->expires_at = $remote['expires_at'] ?? now()->addYears($years);
        $domain->status = 'active';
        $domain->save();

        $this->provisionSsl($domain, renew: true);

        DomainRenewed::dispatch($domain, $years);

        return $domain;
    }

    public function transfer(string $domain, string $authCode, User $user, ?string $provider = null): Domain
    {
        $domain = $this->normalize($domain);

        if (Domain::withoutTenancy()->where('name', $domain)->exists()) {
            throw new DomainUnavailableException("The domain [{$domain}] is already managed.");
        }

        $selected = $this->provider($provider);

        $remote = $selected->transfer($domain, $authCode);

        $model = $this->persistDomain([
            'name' => $domain,
            'status' => 'active',
            'external_id' => $remote['external_id'] ?? null,
            'registered_at' => $remote['registered_at'] ?? now(),
            'expires_at' => $remote['expires_at'] ?? now()->addYear(),
        ], $selected->name());

        $this->provisionSsl($model);

        DomainTransferred::dispatch($model);

        return $model->load('zone.records', 'certificate');
    }

    /**
     * Apply local settings + registry-backed changes (contacts, privacy,
     * transfer lock, nameservers) to a domain, through the registrar that
     * originally registered it.
     *
     * @param  array<string, mixed>  $changes
     */
    public function update(Domain $domain, array $changes): Domain
    {
        $provider = $this->provider($domain->provider);

        $before = [];
        $after = [];

        if (array_key_exists('contacts', $changes)) {
            $before['contacts'] = $domain->contacts;
            $domain->contacts = $changes['contacts'];
            $after['contacts'] = $changes['contacts'];
            $provider->updateContacts($domain->name, $changes['contacts']);
        }

        if (array_key_exists('privacy_protection', $changes)) {
            $value = (bool) $changes['privacy_protection'];
            $before['privacy_protection'] = $domain->privacy_protection;
            $domain->privacy_protection = $value;
            $after['privacy_protection'] = $value;
            $provider->setPrivacy($domain->name, $value);
        }

        if (array_key_exists('transfer_lock', $changes)) {
            $value = (bool) $changes['transfer_lock'];
            $before['transfer_lock'] = $domain->transfer_lock;
            $domain->transfer_lock = $value;
            $after['transfer_lock'] = $value;
            $provider->setTransferLock($domain->name, $value);
        }

        if (array_key_exists('auto_renew', $changes)) {
            $value = (bool) $changes['auto_renew'];
            $before['auto_renew'] = $domain->auto_renew;
            $domain->auto_renew = $value;
            $after['auto_renew'] = $value;
        }

        if (array_key_exists('nameservers', $changes)) {
            $nameservers = array_values(array_filter((array) $changes['nameservers']));
            if (count($nameservers) < 2) {
                throw new DomainUnavailableException('At least two nameservers are required.');
            }

            $before['nameservers'] = $domain->nameservers;
            $domain->nameservers = $nameservers;
            $after['nameservers'] = $nameservers;
            $provider->setNameservers($domain->name, $nameservers);
        }

        if ($after === []) {
            return $domain;
        }

        $domain->save();

        DomainUpdated::dispatch($domain, $before, $after);

        return $domain;
    }

    private function assertRegisterable(string $domain, DomainProviderInterface $provider): void
    {
        if (Domain::withoutTenancy()->where('name', $domain)->exists()) {
            throw new DomainUnavailableException("The domain [{$domain}] is already managed.");
        }

        if (! $provider->checkAvailability($domain)['available']) {
            throw new DomainUnavailableException("The domain [{$domain}] is not available.");
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persistDomain(array $attributes, string $providerName): Domain
    {
        return DB::transaction(function () use ($attributes, $providerName) {
            $domain = Domain::create(array_merge([
                'provider' => $providerName,
                'auto_renew' => true,
                'privacy_protection' => true,
                'transfer_lock' => true,
                'nameservers' => config('omnex.domain.default_nameservers'),
            ], $attributes));

            $zone = DnsZone::create([
                'domain_id' => $domain->id,
                'provider' => $this->dnsProviderName(),
                'status' => 'active',
            ]);

            foreach (config('omnex.domain.default_nameservers') as $nameserver) {
                $zone->records()->create([
                    'type' => 'NS',
                    'name' => '@',
                    'content' => $nameserver,
                    'ttl' => 3600,
                ]);
            }

            return $domain;
        });
    }

    /**
     * DNS provider assigned to a freshly registered domain's zone: the active
     * DNS provider for this organization (settings override → env default).
     */
    /**
     * Auto-provision TLS for a domain (issue on register/transfer, renew on
     * renewal). Best-effort: a failing provider must never block a domain
     * operation — the failure is audited and the certificate can be issued
     * manually through the SSL endpoints later.
     */
    private function provisionSsl(Domain $domain, bool $renew = false): void
    {
        if (! config('omnex.ssl.auto_issue', true)) {
            return;
        }

        // TLS is a plan perk: free tiers don't get managed certificates.
        if (! app(FeatureFlagService::class)->enabled('ssl')) {
            return;
        }

        try {
            $ssl = app(SslService::class);

            if ($renew) {
                $ssl->renew($domain);
            } else {
                $ssl->issue($domain);
            }
        } catch (SslProviderException $e) {
            AuditLogger::record('ssl.provision_failed', 'domain', $domain->id, null, [
                'error' => $e->getMessage(),
            ], 'warning');
        }
    }

    private function dnsProviderName(): string
    {
        $organization = app(TenantContext::class)->organization();

        return (string) ($organization?->settings['dns_provider']
            ?? config('omnex.domain.dns_provider', 'sandbox'));
    }

    private function normalize(string $domain): string
    {
        $domain = strtolower(trim($domain, '. '));

        if (! preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
            throw new DomainUnavailableException("The domain [{$domain}] is not valid.");
        }

        return $domain;
    }
}
