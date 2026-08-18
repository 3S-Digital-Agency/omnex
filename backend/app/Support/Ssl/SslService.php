<?php

namespace App\Support\Ssl;

use App\Contracts\SslProviderInterface;
use App\Models\Domain;
use App\Models\SslCertificate;
use App\Support\Audit\AuditLogger;
use App\Support\Providers\ResolvesTenantProvider;

/**
 * Owns the TLS certificate lifecycle. OMNEX is the system of record
 * (`ssl_certificates`); a SslProviderInterface only issues/renews/revokes the
 * certificate at a CA or edge platform and reports status. Every mutation is
 * audited and the tenant scope keeps each organization's certificates isolated.
 *
 * The active provider is per-organization (`settings.ssl_provider`) with a
 * fallback to OMNEX_SSL_PROVIDER, via ResolvesTenantProvider.
 */
final class SslService
{
    use ResolvesTenantProvider;

    public function __construct(private SslProviderRegistry $providers) {}

    protected function providerConfigKey(): string
    {
        return 'omnex.ssl.provider';
    }

    protected function providerSettingsKey(): string
    {
        return 'ssl_provider';
    }

    /**
     * @return array<int, array{name: string, label: string, configured: bool}>
     */
    public function providers(): array
    {
        return $this->providers->all();
    }

    /**
     * @return array<int, SslCertificate>
     */
    public function certificates(): array
    {
        return SslCertificate::query()
            ->with('domain')
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    public function certificate(Domain $domain): ?SslCertificate
    {
        return SslCertificate::query()->where('domain_id', $domain->id)->first();
    }

    /**
     * Issue (or ensure) a certificate for a domain and persist the result.
     * Idempotent: a domain keeps at most one certificate record.
     */
    public function issue(Domain $domain, ?string $providerName = null): SslCertificate
    {
        $provider = $this->provider($providerName);

        $result = $provider->issue($domain->name);

        $certificate = SslCertificate::updateOrCreate(
            ['domain_id' => $domain->id],
            [
                'organization_id' => $domain->organization_id,
                'provider' => $provider->name(),
                'external_id' => $result['external_id'] ?? null,
                'status' => $result['status'] ?? 'pending',
                'issuer' => $result['issuer'] ?? null,
                'issued_at' => $result['issued_at'] ?? now(),
                'expires_at' => $result['expires_at'] ?? now()->addDays((int) config('omnex.ssl.validity_days', 90)),
                'auto_renew' => $result['auto_renew'] ?? true,
                'certificate_pem' => $result['certificate_pem'] ?? null,
            ],
        );

        AuditLogger::record('ssl.issued', 'domain', $domain->id, null, [
            'provider' => $provider->name(),
            'certificate' => $certificate->id,
            'status' => $certificate->status,
        ]);

        return $certificate->fresh();
    }

    public function renew(Domain $domain): SslCertificate
    {
        $certificate = $this->certificate($domain)
            ?? throw new SslProviderException("No certificate exists for [{$domain->name}] yet.");

        $provider = $this->provider($certificate->provider);

        $result = $provider->renew($domain->name, [
            'external_id' => $certificate->external_id,
            'certificate_pem' => $certificate->certificate_pem,
        ]);

        $certificate->update([
            'status' => $result['status'] ?? 'active',
            'issued_at' => $result['issued_at'] ?? now(),
            'expires_at' => $result['expires_at'] ?? now()->addDays((int) config('omnex.ssl.validity_days', 90)),
            'certificate_pem' => $result['certificate_pem'] ?? $certificate->certificate_pem,
        ]);

        AuditLogger::record('ssl.renewed', 'domain', $domain->id, null, [
            'provider' => $provider->name(),
            'certificate' => $certificate->id,
        ]);

        return $certificate->fresh();
    }

    public function revoke(Domain $domain): SslCertificate
    {
        $certificate = $this->certificate($domain);

        if ($certificate === null) {
            throw new SslProviderException("No certificate exists for [{$domain->name}].");
        }

        $provider = $this->provider($certificate->provider);

        $provider->revoke($domain->name, [
            'external_id' => $certificate->external_id,
            'certificate_pem' => $certificate->certificate_pem,
        ]);

        $certificate->update(['status' => 'revoked']);

        AuditLogger::record('ssl.revoked', 'domain', $domain->id, null, [
            'provider' => $provider->name(),
            'certificate' => $certificate->id,
        ]);

        return $certificate->fresh();
    }

    private function provider(?string $name = null): SslProviderInterface
    {
        $provider = $this->providers->get($name ?? $this->activeProviderName());

        if (! $provider->isConfigured()) {
            throw new SslProviderException("The [{$provider->label()}] SSL provider is not configured.");
        }

        return $provider;
    }
}
