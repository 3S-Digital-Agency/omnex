<?php

namespace App\Support\Ssl\Providers;

use App\Contracts\DnsProviderInterface;
use App\Contracts\SslProviderInterface;
use App\Support\Domains\DnsProviderRegistry;
use App\Support\Providers\ResolvesTenantProvider;
use App\Support\Ssl\Acme\AcmeClient;
use App\Support\Ssl\SslProviderException;
use Illuminate\Support\Facades\File;

/**
 * Real Let's Encrypt certificate provider behind SslProviderInterface.
 *
 * Issuance uses the ACME v2 protocol with the dns-01 challenge: a temporary
 * `_acme-challenge` TXT record is placed through the tenant's active
 * DnsProviderInterface, the CA verifies it, then the record is removed. This
 * works for apex + wildcard domains with no HTTP server or port forwarding.
 *
 * The provider activates only once OMNEX_LETSENCRYPT_EMAIL is set; the ACME
 * account key is generated once and persisted under storage/keys (gitignored)
 * so renewals reuse the same account. Without credentials every call throws
 * SslProviderException, so registering the provider is always safe.
 */
final class LetsEncryptSslProvider implements SslProviderInterface
{
    use ResolvesTenantProvider;

    protected function providerConfigKey(): string
    {
        return 'omnex.domain.dns_provider';
    }

    protected function providerSettingsKey(): string
    {
        return 'dns_provider';
    }

    public function name(): string
    {
        return 'letsencrypt';
    }

    public function label(): string
    {
        return "Let's Encrypt";
    }

    public function isConfigured(): bool
    {
        return ! empty(config('letsencrypt.email'));
    }

    public function issue(string $domain, array $options = []): array
    {
        $this->guardConfigured();

        $result = $this->client()->issue([$domain], $this->dns01Solver());

        return $this->normalizeIssue($result);
    }

    public function renew(string $domain, array $certificate = []): array
    {
        $this->guardConfigured();

        // ACME renewal is a fresh issuance; a new certificate (and key) is
        // produced and the old one simply expires at the CA.
        $result = $this->client()->issue([$domain], $this->dns01Solver());

        return $this->normalizeIssue($result);
    }

    public function revoke(string $domain, array $certificate = []): array
    {
        $this->guardConfigured();

        $fullchain = $certificate['certificate_pem'] ?? null;

        if (empty($fullchain)) {
            throw new SslProviderException("Let's Encrypt revocation requires the stored certificate chain.");
        }

        $this->client()->revoke($fullchain);

        return [];
    }

    public function status(string $domain, array $certificate = []): array
    {
        $this->guardConfigured();

        $expiresAt = $this->expiryFromPem($certificate['certificate_pem'] ?? null);

        return [
            'status' => $expiresAt === null ? 'unknown' : 'active',
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @param  array{fullchain: string, private_key: string, certificate_url: string, order_url: string}  $result
     * @return array{external_id: string, status: string, issuer: string, issued_at: string, expires_at: string, auto_renew: bool, certificate_pem: string, private_key_pem: string}
     */
    private function normalizeIssue(array $result): array
    {
        [$issuedAt, $expiresAt] = $this->validityFromPem($result['fullchain']);

        return [
            'external_id' => $result['certificate_url'],
            'status' => 'active',
            'issuer' => "Let's Encrypt",
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'auto_renew' => true,
            'certificate_pem' => $result['fullchain'],
            'private_key_pem' => $result['private_key'],
        ];
    }

    /**
     * @return array{0: string, 1: string} [issuedAtIso, expiresAtIso]
     */
    private function validityFromPem(string $fullchain): array
    {
        $parsed = openssl_x509_parse($fullchain);

        if ($parsed === false) {
            return [now()->toIso8601String(), now()->addDays((int) config('omnex.ssl.validity_days', 90))->toIso8601String()];
        }

        return [
            isset($parsed['validFrom_time_t']) ? date('c', $parsed['validFrom_time_t']) : now()->toIso8601String(),
            isset($parsed['validTo_time_t']) ? date('c', $parsed['validTo_time_t']) : now()->addDays((int) config('omnex.ssl.validity_days', 90))->toIso8601String(),
        ];
    }

    private function expiryFromPem(?string $fullchain): ?string
    {
        if (empty($fullchain)) {
            return null;
        }

        $parsed = openssl_x509_parse($fullchain);

        return $parsed === false || ! isset($parsed['validTo_time_t'])
            ? null
            : date('c', $parsed['validTo_time_t']);
    }

    /**
     * Build the dns-01 solver: place a TXT record through the tenant's active
     * DNS provider and return a cleanup closure that removes it again.
     */
    private function dns01Solver(): callable
    {
        $dns = $this->dnsProvider();

        return function (string $domain, string $value) use ($dns): callable {
            $record = [
                'type' => 'TXT',
                'name' => '_acme-challenge.'.$this->apex($domain),
                'content' => $value,
                'ttl' => 60,
                'priority' => null,
                'proxied' => false,
            ];

            $dns->createRecord($this->apex($domain), $record);

            return function () use ($dns, $domain, $record): void {
                $dns->deleteRecord($this->apex($domain), $record);
            };
        };
    }

    private function dnsProvider(): DnsProviderInterface
    {
        $provider = app(DnsProviderRegistry::class)->get($this->activeProviderName());

        if (! $provider->isConfigured()) {
            throw new SslProviderException("The [{$provider->label()}] DNS provider is not configured; dns-01 requires a live DNS provider.");
        }

        return $provider;
    }

    /**
     * Strip a wildcard prefix so the challenge record lands on the apex.
     */
    private function apex(string $domain): string
    {
        return str_starts_with($domain, '*.') ? substr($domain, 2) : $domain;
    }

    private function client(): AcmeClient
    {
        return new AcmeClient(
            directoryUrl: (string) config('letsencrypt.directory'),
            accountKeyPem: $this->accountKeyPem(),
            email: (string) config('letsencrypt.email'),
            pollIntervalMs: (int) config('letsencrypt.poll_interval_ms', 3000),
            pollAttempts: (int) config('letsencrypt.poll_attempts', 10),
        );
    }

    /**
     * Load (or generate + persist) the ACME account private key. Persisting it
     * keeps the same account across renewals and avoids re-registration.
     */
    private function accountKeyPem(): string
    {
        $path = (string) config('letsencrypt.account_key_path');

        if ($path !== '' && is_file($path)) {
            $existing = File::get($path);

            if ($existing !== '' && openssl_pkey_get_private($existing) !== false) {
                return $existing;
            }
        }

        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        if ($key === false) {
            throw new SslProviderException("Could not generate the Let's Encrypt account key.");
        }

        openssl_pkey_export($key, $pem);

        if ($path !== '') {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $pem);
        }

        return $pem;
    }

    private function guardConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new SslProviderException(
                "The Let's Encrypt provider requires OMNEX_LETSENCRYPT_EMAIL.",
            );
        }
    }
}
