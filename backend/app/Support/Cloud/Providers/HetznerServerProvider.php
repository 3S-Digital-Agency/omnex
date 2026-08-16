<?php

namespace App\Support\Cloud\Providers;

use App\Contracts\ServerProviderInterface;
use App\Support\Cloud\ServerOperationFailedException;
use App\Support\Cloud\ServerProviderException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Hetzner Cloud (https://api.hetzner.cloud/v1). Activates only once
 * HETZNER_API_TOKEN is set. Region/plan/image map to the platform's
 * location/server_type/image concepts; the operator's defaults in
 * config/hetzner.php apply when a request omits them.
 */
final class HetznerServerProvider implements ServerProviderInterface
{
    use GeneratesSyntheticMetrics;

    private const BASE_URL = 'https://api.hetzner.cloud/v1';

    public function name(): string
    {
        return 'hetzner';
    }

    public function label(): string
    {
        return 'Hetzner';
    }

    public function isConfigured(): bool
    {
        return (string) config('hetzner.token') !== '';
    }

    public function verify(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'detail' => 'HETZNER_API_TOKEN is not set.'];
        }

        // Read-only authenticated call (list servers, page size 1) — proves
        // the token without provisioning anything or incurring cost.
        $response = $this->http()->get(self::BASE_URL.'/servers', ['per_page' => 1]);

        if ($response->successful()) {
            return ['ok' => true, 'detail' => 'Hetzner API reachable with the configured token.'];
        }

        $message = $response->json('error.message');

        return [
            'ok' => false,
            'detail' => is_string($message) && $message !== ''
                ? 'Hetzner: '.$message
                : 'Hetzner: HTTP '.$response->status().'.',
        ];
    }

    public function provision(string $name, string $region, string $plan, string $image, string $sshKey): array
    {
        $response = $this->http()->post(self::BASE_URL.'/servers', [
            'name' => $name,
            'location' => $region !== '' ? $region : (string) config('hetzner.default_location', 'fsn1'),
            'server_type' => $plan !== '' ? $plan : (string) config('hetzner.default_server_type', 'cpx11'),
            'image' => $image !== '' ? $image : (string) config('hetzner.default_image', 'ubuntu-24.04'),
            'ssh_keys' => $this->sshKeys($sshKey),
        ]);

        $this->assertOk($response, 'Hetzner could not provision the server');

        $server = $response->json('server') ?? [];

        return [
            'provider_server_id' => (string) ($server['id'] ?? ''),
            'ipv4' => (string) ($server['public_net']['ipv4']['ip'] ?? ''),
            'ipv6' => $server['public_net']['ipv6']['ip'] ?? null,
            'status' => $this->mapStatus((string) ($server['status'] ?? 'unknown')),
        ];
    }

    public function start(string $providerServerId): void
    {
        $this->action($providerServerId, 'start');
    }

    public function stop(string $providerServerId): void
    {
        $this->action($providerServerId, 'stop');
    }

    public function reboot(string $providerServerId): void
    {
        $this->action($providerServerId, 'reboot');
    }

    public function rebuild(string $providerServerId, string $image): array
    {
        $response = $this->http()->post(self::BASE_URL."/servers/{$providerServerId}/actions/rebuild", [
            'image' => $image !== '' ? $image : (string) config('hetzner.default_image', 'ubuntu-24.04'),
        ]);

        $this->assertOk($response, 'Hetzner could not rebuild the server');

        $server = $response->json('server') ?? [];

        return [
            'ipv4' => (string) ($server['public_net']['ipv4']['ip'] ?? ''),
            'ipv6' => $server['public_net']['ipv6']['ip'] ?? null,
        ];
    }

    public function delete(string $providerServerId): void
    {
        $response = $this->http()->delete(self::BASE_URL."/servers/{$providerServerId}");

        // 404 means the server is already gone — treat as success (idempotent).
        if ($response->status() !== 404) {
            $this->assertOk($response, 'Hetzner could not delete the server');
        }
    }

    public function metrics(string $providerServerId): array
    {
        // Hetzner exposes time-series CPU metrics; their aggregation is not
        // wired yet, so the stream uses a deterministic synthetic sample.
        return $this->sampleSyntheticMetrics($providerServerId);
    }

    public function installSshKey(string $providerServerId, string $publicKey): array
    {
        // Hetzner applies SSH keys at provisioning / rebuild time; there is
        // no API to add a key to a running server. Report this honestly so
        // the operation trail shows the key must be wired at next rebuild.
        return [
            'status' => 'unsupported',
            'detail' => 'Hetzner applies SSH keys at provisioning or rebuild time; re-provision to install this key.',
        ];
    }

    public function snapshot(string $providerServerId, string $label): array
    {
        $response = $this->http()->post(self::BASE_URL."/servers/{$providerServerId}/actions/create_image", [
            'type' => 'snapshot',
            'description' => $label,
        ]);

        $this->assertOk($response, 'Hetzner could not create the snapshot');

        $image = $response->json('image') ?? [];

        return [
            'provider_snapshot_id' => (string) ($image['id'] ?? ''),
            'status' => 'available',
        ];
    }

    public function listSnapshots(string $providerServerId): array
    {
        $response = $this->http()->get(self::BASE_URL.'/images', [
            'type' => 'snapshot',
            'sort' => 'created:desc',
        ]);

        $this->assertOk($response, 'Hetzner could not list the snapshots');

        return array_map(function (array $image) {
            return [
                'provider_snapshot_id' => (string) ($image['id'] ?? ''),
                'label' => (string) ($image['description'] ?? ''),
                'status' => $this->mapImageStatus((string) ($image['status'] ?? 'available')),
                'created_at' => isset($image['created']) ? $this->toIso8601($image['created']) : null,
            ];
        }, $response->json('images') ?? []);
    }

    public function deleteSnapshot(string $providerServerId, string $providerSnapshotId): void
    {
        $response = $this->http()->delete(self::BASE_URL."/images/{$providerSnapshotId}");

        // 404 means the snapshot is already gone — treat as success.
        if ($response->status() !== 404) {
            $this->assertOk($response, 'Hetzner could not delete the snapshot');
        }
    }

    private function mapImageStatus(string $status): string
    {
        return $status === 'creating' ? 'creating' : ($status === 'available' ? 'available' : 'error');
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
        $configured = (string) config('hetzner.ssh_key_id', '');

        return array_values(array_filter([$sshKey, $configured], fn ($key) => $key !== ''));
    }

    private function action(string $providerServerId, string $type): void
    {
        $response = $this->http()->post(self::BASE_URL."/servers/{$providerServerId}/actions/{$type}");

        $this->assertOk($response, "Hetzner could not {$type} the server");
    }

    private function mapStatus(string $status): string
    {
        return match ($status) {
            'running' => 'running',
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
        $message = $json['error']['message'] ?? null;

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
        return Http::withToken((string) config('hetzner.token'))
            ->acceptJson()
            ->timeout(30);
    }
}
