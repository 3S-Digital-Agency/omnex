<?php

namespace App\Support\Ssl\Providers;

use App\Contracts\SslProviderInterface;
use App\Support\Ssl\SslProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Real Cloudflare edge-certificate provider behind SslProviderInterface.
 *
 * Cloudflare terminates TLS at its edge: enabling Universal SSL on a zone
 * issues the certificate (and Cloudflare renews it automatically). This
 * provider therefore maps the certificate lifecycle onto the documented
 * `PATCH /zones/{id}/ssl/universal/settings {enabled: …}` API. A non-Cloudflare
 * domain must be attached to a zone first (see CloudflareDnsProvider).
 *
 * Without CLOUDFLARE_API_TOKEN every call throws SslProviderException, so
 * registering the provider is always safe; the sandbox remains the default.
 */
final class CloudflareSslProvider implements SslProviderInterface
{
    private const TIMEOUT_SECONDS = 30;

    public function name(): string
    {
        return 'cloudflare';
    }

    public function label(): string
    {
        return 'Cloudflare SSL';
    }

    public function isConfigured(): bool
    {
        return config('cloudflare.api_token') !== null && config('cloudflare.api_token') !== '';
    }

    public function issue(string $domain, array $options = []): array
    {
        $this->guardConfigured();

        $zone = $this->findZoneOrFail($domain);
        $result = $this->setEnabled($zone['id'], true);

        return [
            'external_id' => $zone['id'],
            'status' => $this->normalizeStatus($result['certificate_status'] ?? 'pending'),
            'issuer' => 'Cloudflare',
            'issued_at' => now()->toIso8601String(),
            'expires_at' => now()->addDays((int) config('omnex.ssl.validity_days', 90))->toIso8601String(),
            'auto_renew' => true,
        ];
    }

    public function renew(string $domain, array $certificate = []): array
    {
        $this->guardConfigured();

        // Universal SSL renews continuously at the edge; "renew" re-asserts the
        // enabled state (idempotent) so a previously revoked edge cert returns.
        $result = $this->setEnabled($certificate['external_id'] ?? $this->findZoneOrFail($domain)['id'], true);

        return [
            'external_id' => $certificate['external_id'] ?? $result['id'] ?? '',
            'status' => $this->normalizeStatus($result['certificate_status'] ?? 'active'),
            'issued_at' => now()->toIso8601String(),
            'expires_at' => now()->addDays((int) config('omnex.ssl.validity_days', 90))->toIso8601String(),
        ];
    }

    public function revoke(string $domain, array $certificate = []): array
    {
        $this->guardConfigured();

        $zoneId = $certificate['external_id'] ?? $this->findZoneOrFail($domain)['id'];
        $this->setEnabled($zoneId, false);

        return [];
    }

    public function status(string $domain, array $certificate = []): array
    {
        $this->guardConfigured();

        $zoneId = $certificate['external_id'] ?? $this->findZoneOrFail($domain)['id'];
        $result = $this->getSettings($zoneId);

        return [
            'status' => $this->normalizeStatus($result['certificate_status'] ?? 'expired'),
            'expires_at' => now()->addDays((int) config('omnex.ssl.validity_days', 90))->toIso8601String(),
        ];
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private function findZone(string $domain): ?array
    {
        $response = $this->send('get', '/zones', ['name' => $domain, 'per_page' => 1]);
        $zone = $response->json('result.0');

        return $zone ? ['id' => $zone['id'], 'name' => $zone['name']] : null;
    }

    /**
     * @return array{id: string, name: string}
     */
    private function findZoneOrFail(string $domain): array
    {
        $zone = $this->findZone($domain);

        if ($zone === null) {
            throw new SslProviderException("Cloudflare has no zone for [{$domain}]. Attach the domain to a zone first.");
        }

        return $zone;
    }

    /**
     * @return array<string, mixed>
     */
    private function setEnabled(string $zoneId, bool $enabled): array
    {
        $response = $this->send('patch', "/zones/{$zoneId}/ssl/universal/settings", ['enabled' => $enabled]);

        return $response->json('result') ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function getSettings(string $zoneId): array
    {
        $response = $this->send('get', "/zones/{$zoneId}/ssl/universal/settings");

        return $response->json('result') ?? [];
    }

    private function normalizeStatus(string $cloudflareStatus): string
    {
        return match ($cloudflareStatus) {
            'active', 'pending_validation', 'initializing', 'authorizing', 'pending_deployment' => 'active',
            'pending_expiration', 'expired' => 'expiring',
            default => strtolower($cloudflareStatus),
        };
    }

    /**
     * Execute a Cloudflare API call and raise on auth/API errors.
     *
     * @param  array<string, mixed>  $bodyOrQuery
     */
    private function send(string $method, string $path, array $bodyOrQuery = []): Response
    {
        $headers = [
            'Authorization' => 'Bearer '.config('cloudflare.api_token'),
            'Accept' => 'application/json',
        ];

        try {
            $response = $method === 'get'
                ? Http::timeout(self::TIMEOUT_SECONDS)->withHeaders($headers)->get(
                    config('cloudflare.endpoint').$path, $bodyOrQuery,
                )
                : Http::timeout(self::TIMEOUT_SECONDS)->withHeaders($headers)->{$method}(
                    config('cloudflare.endpoint').$path, $bodyOrQuery,
                );
        } catch (ConnectionException $e) {
            throw new SslProviderException("Cloudflare SSL is unreachable: {$e->getMessage()}", previous: $e);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new SslProviderException('Cloudflare rejected the API token (401/403). Check CLOUDFLARE_API_TOKEN.');
        }

        if ($response->failed() || ($response->json('success') === false)) {
            $message = collect($response->json('errors') ?? [])
                ->map(fn (array $error) => "{$error['code']} {$error['message']}")
                ->implode('; ');

            throw new SslProviderException(
                'Cloudflare API error: '.($message ?: $response->status()),
            );
        }

        return $response;
    }

    private function guardConfigured(): void
    {
        if (empty(config('cloudflare.api_token'))) {
            throw new SslProviderException(
                'Cloudflare SSL provider requires CLOUDFLARE_API_TOKEN.',
            );
        }
    }
}
