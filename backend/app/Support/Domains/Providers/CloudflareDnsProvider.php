<?php

namespace App\Support\Domains\Providers;

use App\Contracts\DnsProviderInterface;
use App\Support\Domains\DomainProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Real Cloudflare managed-DNS provider behind DnsProviderInterface.
 *
 * Talks to the Cloudflare v4 API (api.cloudflare.com/client/v4) over HTTPS via
 * the Laravel HTTP client (Guzzle), authenticated with an API token. Zone
 * lookups are by exact name, records are matched (name + type + content) for
 * idempotent update/delete, and each record can be proxied through Cloudflare's
 * CDN (`_proxied`).
 *
 * DNSSEC: PATCH /zones/{id}/dnssec {status: active} returns the signing state
 * and the DS record (key tag / algorithm / digest type / digest) which the
 * registrar must publish in the parent zone.
 *
 * Without CLOUDFLARE_API_TOKEN every call throws DomainProviderException, so
 * registering the provider is always safe; the sandbox remains the default.
 */
final class CloudflareDnsProvider implements DnsProviderInterface
{
    private const TIMEOUT_SECONDS = 30;

    public function name(): string
    {
        return 'cloudflare';
    }

    public function label(): string
    {
        return 'Cloudflare DNS';
    }

    public function isConfigured(): bool
    {
        return config('cloudflare.api_token') !== null && config('cloudflare.api_token') !== '';
    }

    /** @throws DomainProviderException */
    public function createZone(string $domain): array
    {
        $this->guardConfigured();

        $existing = $this->findZone($domain);
        if ($existing !== null) {
            return ['external_id' => $existing['id']];
        }

        $payload = ['name' => $domain, 'type' => 'full'];
        if ($accountId = config('cloudflare.account_id')) {
            $payload['account'] = ['id' => $accountId];
        }

        $response = $this->send('post', '/zones', $payload);

        return ['external_id' => $response->json('result.id')];
    }

    /** @throws DomainProviderException */
    public function deleteZone(string $domain): array
    {
        $this->guardConfigured();

        $zone = $this->findZoneOrFail($domain);
        $this->send('delete', "/zones/{$zone['id']}");

        return [];
    }

    /** @throws DomainProviderException */
    public function createRecord(string $domain, array $record): array
    {
        $this->guardConfigured();

        $zone = $this->findZoneOrFail($domain);
        $existing = $this->findRecord($zone['id'], $record['name'], $record['type'], $record['content']);
        if ($existing !== null) {
            return ['external_id' => $existing['id']];
        }

        $response = $this->send('post', "/zones/{$zone['id']}/dns_records", $this->recordPayload($record));

        return ['external_id' => $response->json('result.id')];
    }

    /** @throws DomainProviderException */
    public function updateRecord(string $domain, array $record): array
    {
        $this->guardConfigured();

        $zone = $this->findZoneOrFail($domain);
        $existing = $this->findRecord($zone['id'], $record['name'], $record['type'], $record['content']);
        if ($existing === null) {
            // Record is not on the DNS plane yet — create it rather than fail
            // (OMNEX is the system of record and may have changed the value).
            return $this->createRecord($domain, $record);
        }

        $this->send('patch', "/zones/{$zone['id']}/dns_records/{$existing['id']}", $this->recordPayload($record));

        return [];
    }

    /** @throws DomainProviderException */
    public function deleteRecord(string $domain, array $record): array
    {
        $this->guardConfigured();

        $zone = $this->findZoneOrFail($domain);
        $existing = $this->findRecord($zone['id'], $record['name'], $record['type'], $record['content']);
        if ($existing !== null) {
            $this->send('delete', "/zones/{$zone['id']}/dns_records/{$existing['id']}");
        }

        return [];
    }

    /** @throws DomainProviderException */
    public function enableDnssec(string $domain): array
    {
        $this->guardConfigured();

        $zone = $this->findZoneOrFail($domain);
        $response = $this->send('patch', "/zones/{$zone['id']}/dnssec", ['status' => 'active']);

        $result = $response->json('result') ?? [];

        // Cloudflare v4 returns the DS either as parsed fields or as the
        // space-separated `ds` string ("<key_tag> <algorithm> <digest_type> <digest>").
        if (isset($result['ds']) && is_string($result['ds'])) {
            $parts = preg_split('/\s+/', trim($result['ds']));
            if (count($parts) >= 4) {
                return [[
                    'key_tag' => (int) $parts[0],
                    'algorithm' => (int) $parts[1],
                    'digest_type' => (int) $parts[2],
                    'digest' => strtoupper($parts[3]),
                ]];
            }
        }

        if (isset($result['key_tag'], $result['algorithm'], $result['digest_type'], $result['digest'])) {
            return [[
                'key_tag' => (int) $result['key_tag'],
                'algorithm' => (int) $result['algorithm'],
                'digest_type' => (int) $result['digest_type'],
                'digest' => strtoupper($result['digest']),
            ]];
        }

        throw new DomainProviderException('Cloudflare did not return a DS record for the zone.');
    }

    /** @throws DomainProviderException */
    public function disableDnssec(string $domain): array
    {
        $this->guardConfigured();

        $zone = $this->findZoneOrFail($domain);
        $this->send('patch', "/zones/{$zone['id']}/dnssec", ['status' => 'disabled']);

        return [];
    }

    /**
     * OMNEX record shape → Cloudflare v4 payload. `proxied` only applies to
     * proxiable record types (A, AAAA, CNAME); MX/SRV/etc. stay unproxied.
     *
     * @param  array{name: string, type: string, content: string, ttl: int, priority: ?int, proxied: bool}  $record
     * @return array<string, mixed>
     */
    private function recordPayload(array $record): array
    {
        $payload = [
            'type' => $record['type'],
            'name' => $record['name'],
            'content' => $record['content'],
            'ttl' => (int) ($record['ttl'] ?? 1),
        ];

        if (in_array($record['type'], ['A', 'AAAA', 'CNAME'], true) && ($record['proxied'] ?? false)) {
            $payload['proxied'] = true;
        }

        if (! empty($record['priority']) && $record['type'] === 'MX') {
            $payload['priority'] = (int) $record['priority'];
        }

        return $payload;
    }

    /** @return array{id: string, name: string}|null */
    private function findZone(string $domain): ?array
    {
        $response = $this->send('get', '/zones', ['name' => $domain, 'per_page' => 1]);
        $zone = $response->json('result.0');

        return $zone ? ['id' => $zone['id'], 'name' => $zone['name']] : null;
    }

    /** @return array{id: string, name: string} */
    private function findZoneOrFail(string $domain): array
    {
        $zone = $this->findZone($domain);
        if ($zone === null) {
            throw new DomainProviderException("Cloudflare has no zone for [{$domain}].");
        }

        return $zone;
    }

    /** @return array{id: string}|null */
    private function findRecord(string $zoneId, string $name, string $type, string $content): ?array
    {
        $response = $this->send('get', "/zones/{$zoneId}/dns_records", ['type' => $type, 'name' => $name, 'per_page' => 100]);
        $records = $response->json('result') ?? [];

        foreach ($records as $record) {
            if (strcasecmp((string) ($record['content'] ?? ''), $content) === 0) {
                return ['id' => $record['id']];
            }
        }

        return count($records) === 1 ? ['id' => $records[0]['id']] : null;
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
            throw new DomainProviderException("Cloudflare DNS is unreachable: {$e->getMessage()}", previous: $e);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new DomainProviderException('Cloudflare rejected the API token (401/403). Check CLOUDFLARE_API_TOKEN.');
        }

        if ($response->failed() || ($response->json('success') === false)) {
            $message = collect($response->json('errors') ?? [])
                ->map(fn (array $error) => "{$error['code']} {$error['message']}")
                ->implode('; ');

            throw new DomainProviderException(
                'Cloudflare API error: '.($message ?: $response->status()),
            );
        }

        return $response;
    }

    /**
     * @throws DomainProviderException
     */
    private function guardConfigured(): void
    {
        if (empty(config('cloudflare.api_token'))) {
            throw new DomainProviderException(
                'Cloudflare provider requires CLOUDFLARE_API_TOKEN. Set it in config/cloudflare.php.',
            );
        }
    }
}
