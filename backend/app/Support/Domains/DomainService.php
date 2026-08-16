<?php

namespace App\Support\Domains;

use App\Contracts\DomainProviderInterface;
use App\Events\DomainRegistered;
use App\Events\DomainRenewed;
use App\Events\DomainTransferred;
use App\Events\DomainUpdated;
use App\Models\Domain;
use App\Models\DnsZone;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Owns the domain lifecycle. OMNEX is the system of record; the configured
 * DomainProviderInterface only performs registry operations. Events are the
 * integration surface (audit, notifications, automation).
 */
final class DomainService
{
    public function __construct(private DomainProviderRegistry $providers)
    {
    }

    private function provider(): DomainProviderInterface
    {
        return $this->providers->get();
    }

    public function search(string $query, array $tlds = []): array
    {
        if (trim($query) === '') {
            return [];
        }

        return $this->provider()->search($query, $tlds);
    }

    public function check(string $domain): array
    {
        $domain = $this->normalize($domain);

        $result = $this->provider()->checkAvailability($domain);
        $result['managed'] = Domain::withoutTenancy()->where('name', $domain)->exists();

        return $result;
    }

    public function register(string $domain, array $options, User $user): Domain
    {
        $domain = $this->normalize($domain);

        $this->assertRegisterable($domain);

        $remote = $this->provider()->register($domain, $options);

        $model = $this->persistDomain([
            'name' => $domain,
            'status' => 'active',
            'external_id' => $remote['external_id'] ?? null,
            'registered_at' => $remote['registered_at'] ?? now(),
            'expires_at' => $remote['expires_at'] ?? now()->addYear(),
        ]);

        DomainRegistered::dispatch($model);

        return $model->load('zone.records');
    }

    public function renew(Domain $domain, int $years): Domain
    {
        $years = max(1, $years);

        $remote = $this->provider()->renew($domain->name, $years);

        $domain->expires_at = $remote['expires_at'] ?? now()->addYears($years);
        $domain->status = 'active';
        $domain->save();

        DomainRenewed::dispatch($domain, $years);

        return $domain;
    }

    public function transfer(string $domain, string $authCode, User $user): Domain
    {
        $domain = $this->normalize($domain);

        if (Domain::withoutTenancy()->where('name', $domain)->exists()) {
            throw new DomainUnavailableException("The domain [{$domain}] is already managed.");
        }

        $remote = $this->provider()->transfer($domain, $authCode);

        $model = $this->persistDomain([
            'name' => $domain,
            'status' => 'active',
            'external_id' => $remote['external_id'] ?? null,
            'registered_at' => $remote['registered_at'] ?? now(),
            'expires_at' => $remote['expires_at'] ?? now()->addYear(),
        ]);

        DomainTransferred::dispatch($model);

        return $model->load('zone.records');
    }

    /**
     * Apply local settings + registry-backed changes (contacts, privacy,
     * transfer lock, nameservers) to a domain.
     *
     * @param  array<string, mixed>  $changes
     */
    public function update(Domain $domain, array $changes): Domain
    {
        $before = [];
        $after = [];

        if (array_key_exists('contacts', $changes)) {
            $before['contacts'] = $domain->contacts;
            $domain->contacts = $changes['contacts'];
            $after['contacts'] = $changes['contacts'];
            $this->provider()->updateContacts($domain->name, $changes['contacts']);
        }

        if (array_key_exists('privacy_protection', $changes)) {
            $value = (bool) $changes['privacy_protection'];
            $before['privacy_protection'] = $domain->privacy_protection;
            $domain->privacy_protection = $value;
            $after['privacy_protection'] = $value;
            $this->provider()->setPrivacy($domain->name, $value);
        }

        if (array_key_exists('transfer_lock', $changes)) {
            $value = (bool) $changes['transfer_lock'];
            $before['transfer_lock'] = $domain->transfer_lock;
            $domain->transfer_lock = $value;
            $after['transfer_lock'] = $value;
            $this->provider()->setTransferLock($domain->name, $value);
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
            $this->provider()->setNameservers($domain->name, $nameservers);
        }

        if ($after === []) {
            return $domain;
        }

        $domain->save();

        DomainUpdated::dispatch($domain, $before, $after);

        return $domain;
    }

    private function assertRegisterable(string $domain): void
    {
        if (Domain::withoutTenancy()->where('name', $domain)->exists()) {
            throw new DomainUnavailableException("The domain [{$domain}] is already managed.");
        }

        if (! $this->provider()->checkAvailability($domain)['available']) {
            throw new DomainUnavailableException("The domain [{$domain}] is not available.");
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persistDomain(array $attributes): Domain
    {
        return DB::transaction(function () use ($attributes) {
            $domain = Domain::create(array_merge([
                'provider' => $this->provider()->name(),
                'auto_renew' => true,
                'privacy_protection' => true,
                'transfer_lock' => true,
                'nameservers' => config('nexus.domain.default_nameservers'),
            ], $attributes));

            $zone = DnsZone::create([
                'domain_id' => $domain->id,
                'provider' => config('nexus.domain.dns_provider', 'sandbox'),
                'status' => 'active',
            ]);

            foreach (config('nexus.domain.default_nameservers') as $nameserver) {
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

    private function normalize(string $domain): string
    {
        $domain = strtolower(trim($domain, '. '));

        if (! preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
            throw new DomainUnavailableException("The domain [{$domain}] is not valid.");
        }

        return $domain;
    }
}
