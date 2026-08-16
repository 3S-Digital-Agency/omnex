<?php

namespace App\Support\Cloud\Providers;

use App\Contracts\ServerProviderInterface;
use App\Support\Cloud\ServerOperationFailedException;
use App\Support\Cloud\ServerProviderException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * DigitalOcean (https://api.digitalocean.com/v2). Activates only once
 * DO_API_TOKEN is set. Region/plan/image map to the platform's
 * region/size/image concepts; the operator's defaults in config/digitalocean.php
 * apply when a request omits them.
 */
final class DigitalOceanServerProvider implements ServerProviderInterface
{
    use GeneratesSyntheticMetrics;

    private const BASE_URL = 'https://api.digitalocean.com/v2';

    public function name(): string
    {
        return 'digitalocean';
    }

    public function label(): string
    {
        return 'DigitalOcean';
    }

    public function isConfigured(): bool
    {
        return (string) config('digitalocean.token') !== '';
    }

    public function verify(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'detail' => 'DO_API_TOKEN is not set.'];
        }

        // Read-only authenticated call (GET /account) — proves the token
        // without provisioning anything or incurring cost.
        $response = $this->http()->get(self::BASE_URL.'/account');

        if ($response->successful()) {
            return ['ok' => true, 'detail' => 'DigitalOcean API reachable with the configured token.'];
        }

        $message = $response->json('message');

        return [
            'ok' => false,
            'detail' => is_string($message) && $message !== ''
                ? 'DigitalOcean: '.$message
                : 'DigitalOcean: HTTP '.$response->status().'.',
        ];
    }

    public function provision(string $name, string $region, string $plan, string $image, string $sshKey): array
    {
        $response = $this->http()->post(self::BASE_URL.'/droplets', [
            'name' => $name,
            'region' => $region !== '' ? $region : (string) config('digitalocean.default_region', 'nyc1'),
            'size' => $plan !== '' ? $plan : (string) config('digitalocean.default_size', 's-1vcpu-1gb'),
            'image' => $image !== '' ? $image : (string) config('digitalocean.default_image', 'ubuntu-24-04-x64'),
            'ssh_keys' => $this->sshKeys($sshKey),
        ]);

        $this->assertOk($response, 'DigitalOcean could not provision the droplet');

        $droplet = $response->json('droplet') ?? [];

        return [
            'provider_server_id' => (string) ($droplet['id'] ?? ''),
            'ipv4' => (string) ($droplet['networks']['v4'][0]['ip_address'] ?? ''),
            'ipv6' => $droplet['networks']['v6'][0]['ip_address'] ?? null,
            'status' => $this->mapStatus((string) ($droplet['status'] ?? 'new')),
        ];
    }

    public function start(string $providerServerId): void
    {
        $this->action($providerServerId, 'power_on');
    }

    public function stop(string $providerServerId): void
    {
        $this->action($providerServerId, 'shutdown');
    }

    public function reboot(string $providerServerId): void
    {
        $this->action($providerServerId, 'reboot');
    }

    public function rebuild(string $providerServerId, string $image): array
    {
        $response = $this->http()->post(self::BASE_URL."/droplets/{$providerServerId}/actions", [
            'type' => 'rebuild',
            'image' => $image !== '' ? $image : (string) config('digitalocean.default_image', 'ubuntu-24-04-x64'),
        ]);

        $this->assertOk($response, 'DigitalOcean could not rebuild the droplet');

        return [
            'ipv4' => '',
            'ipv6' => null,
        ];
    }

    public function delete(string $providerServerId): void
    {
        $response = $this->http()->delete(self::BASE_URL."/droplets/{$providerServerId}");

        // 404 means the droplet is already gone — treat as success (idempotent).
        if ($response->status() !== 404) {
            $this->assertOk($response, 'DigitalOcean could not delete the droplet');
        }
    }

    public function metrics(string $providerServerId): array
    {
        // DigitalOcean exposes time-series metrics; their aggregation is not
        // wired yet, so the stream uses a deterministic synthetic sample.
        return $this->sampleSyntheticMetrics($providerServerId);
    }

    public function installSshKey(string $providerServerId, string $publicKey): array
    {
        // DigitalOcean applies SSH keys at droplet creation / rebuild time;
        // there is no API to add a key to a running droplet. Report this
        // honestly so the operation trail shows the key must be wired at
        // next rebuild.
        return [
            'status' => 'unsupported',
            'detail' => 'DigitalOcean applies SSH keys at droplet creation or rebuild time; re-provision to install this key.',
        ];
    }

    public function snapshot(string $providerServerId, string $label): array
    {
        $response = $this->http()->post(self::BASE_URL."/droplets/{$providerServerId}/actions", [
            'type' => 'snapshot',
            'name' => $label,
        ]);

        $this->assertOk($response, 'DigitalOcean could not create the snapshot');

        // The snapshot id is not known synchronously (the action runs
        // async); the reconcile step in listSnapshots resolves it.
        return [
            'provider_snapshot_id' => 'do-snap-pending-'.substr(hash('sha256', $providerServerId.':'.$label.':'.time()), 0, 8),
            'status' => 'creating',
        ];
    }

    public function listSnapshots(string $providerServerId): array
    {
        $response = $this->http()->get(self::BASE_URL."/droplets/{$providerServerId}/snapshots");

        $this->assertOk($response, 'DigitalOcean could not list the snapshots');

        return array_map(function (array $snapshot) {
            return [
                'provider_snapshot_id' => (string) ($snapshot['id'] ?? ''),
                'label' => (string) ($snapshot['name'] ?? ''),
                'status' => $snapshot['status'] ?? 'available',
                'created_at' => isset($snapshot['created_at']) ? $this->toIso8601((string) $snapshot['created_at']) : null,
            ];
        }, $response->json('snapshots') ?? []);
    }

    public function deleteSnapshot(string $providerServerId, string $providerSnapshotId): void
    {
        $response = $this->http()->delete(self::BASE_URL."/snapshots/{$providerSnapshotId}");

        // 404 means the snapshot is already gone — treat as success.
        if ($response->status() !== 404) {
            $this->assertOk($response, 'DigitalOcean could not delete the snapshot');
        }
    }

    private function toIso8601(string $value): ?string
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $value);

        return $parsed ? $parsed->format('Y-m-d\TH:i:s\Z') : null;
    }

    /**
     * @return array<int, string>
     */
    private function sshKeys(string $sshKey): array
    {
        $configured = (string) config('digitalocean.ssh_key_id', '');

        return array_values(array_filter([$sshKey, $configured], fn ($key) => $key !== ''));
    }

    private function action(string $providerServerId, string $type): void
    {
        $response = $this->http()->post(self::BASE_URL."/droplets/{$providerServerId}/actions", [
            'type' => $type,
        ]);

        $this->assertOk($response, "DigitalOcean could not run [{$type}] on the droplet");
    }

    private function mapStatus(string $status): string
    {
        return match ($status) {
            'active' => 'running',
            'off' => 'stopped',
            default => 'provisioning',
        };
    }

    private function assertOk(Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }

        $json = $response->json();
        $message = $json['message'] ?? ($json['id'] ?? null);

        if (is_string($message) && $message !== '') {
            throw new ServerOperationFailedException("{$context}: {$message}");
        }

        if ($response->status() >= 500) {
            throw new ServerProviderException("{$context} (HTTP {$response->status()}).");
        }

        throw new ServerOperationFailedException("{$context} (HTTP {$response->status()}).");
    }

    private function http()
    {
        return Http::withToken((string) config('digitalocean.token'))
            ->acceptJson()
            ->timeout(30);
    }
}
