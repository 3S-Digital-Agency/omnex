<?php

namespace App\Support\Cloud\Providers;

use App\Contracts\ServerProviderInterface;
use App\Support\Cloud\ServerOperationFailedException;
use App\Support\Cloud\ServerProviderException;
use Illuminate\Support\Facades\Http;

/**
 * Bring-your-own compute platform behind ServerProviderInterface.
 *
 * Points at any HTTP/JSON gateway the operator configures
 * (config/customcloud.php). Requests are POSTed as JSON with an optional
 * Bearer token; the gateway replies with the same shapes the interface
 * expects under a `data` key:
 *
 *   {"command": "provision", "name": "…", "region": "…", …}
 *   → {"data": {"provider_server_id": "…", "ipv4": "…", "ipv6": null, "status": "running"}}
 *
 * Commands: provision, start, stop, reboot, rebuild, delete. A failed
 * operation must return an HTTP error with {"error": "…"}.
 */
final class CustomServerProvider implements ServerProviderInterface
{
    public function name(): string
    {
        return 'custom';
    }

    public function label(): string
    {
        return 'Custom';
    }

    public function isConfigured(): bool
    {
        return $this->endpoint() !== '';
    }

    public function verify(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'detail' => 'CUSTOM_CLOUD_ENDPOINT is not set.'];
        }

        try {
            $result = $this->call('ping', ['provider_server_id' => '']);

            return ['ok' => true, 'detail' => 'Custom gateway answered the ping command.'];
        } catch (ServerOperationFailedException $e) {
            return ['ok' => false, 'detail' => 'Custom gateway: '.$e->getMessage()];
        } catch (ServerProviderException $e) {
            return ['ok' => false, 'detail' => 'Custom gateway: '.$e->getMessage()];
        }
    }

    public function provision(string $name, string $region, string $plan, string $image, string $sshKey): array
    {
        return $this->call('provision', [
            'name' => $name,
            'region' => $region,
            'plan' => $plan,
            'image' => $image,
            'ssh_key' => $sshKey,
        ]);
    }

    public function start(string $providerServerId): void
    {
        $this->call('start', ['provider_server_id' => $providerServerId]);
    }

    public function stop(string $providerServerId): void
    {
        $this->call('stop', ['provider_server_id' => $providerServerId]);
    }

    public function reboot(string $providerServerId): void
    {
        $this->call('reboot', ['provider_server_id' => $providerServerId]);
    }

    public function rebuild(string $providerServerId, string $image): array
    {
        return $this->call('rebuild', [
            'provider_server_id' => $providerServerId,
            'image' => $image,
        ]);
    }

    public function delete(string $providerServerId): void
    {
        $this->call('delete', ['provider_server_id' => $providerServerId]);
    }

    public function metrics(string $providerServerId): array
    {
        return $this->call('metrics', ['provider_server_id' => $providerServerId]);
    }

    public function snapshot(string $providerServerId, string $label): array
    {
        return $this->call('snapshot', [
            'provider_server_id' => $providerServerId,
            'label' => $label,
        ]);
    }

    public function listSnapshots(string $providerServerId): array
    {
        return $this->call('list_snapshots', ['provider_server_id' => $providerServerId]);
    }

    public function deleteSnapshot(string $providerServerId, string $providerSnapshotId): void
    {
        $this->call('delete_snapshot', [
            'provider_server_id' => $providerServerId,
            'provider_snapshot_id' => $providerSnapshotId,
        ]);
    }

    public function installSshKey(string $providerServerId, string $publicKey): array
    {
        return $this->call('install_ssh_key', [
            'provider_server_id' => $providerServerId,
            'public_key' => $publicKey,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $command, array $payload): array
    {
        if (! $this->isConfigured()) {
            throw new ServerProviderException('Custom cloud provider is not configured (missing endpoint).');
        }

        $response = Http::timeout(30)
            ->withHeaders(['Accept' => 'application/json'])
            ->when($this->apiKey() !== '', fn ($http) => $http->withToken($this->apiKey()))
            ->post($this->endpoint(), array_merge(['command' => $command], $payload));

        if ($response->failed()) {
            $json = $response->json();
            $message = $json['error'] ?? "Custom cloud provider failed with HTTP {$response->status()}.";

            throw new ServerOperationFailedException((string) $message);
        }

        $json = $response->json();

        if (is_array($json['data'] ?? null)) {
            return $json['data'];
        }

        if (is_array($json)) {
            return $json;
        }

        throw new ServerProviderException('Custom cloud provider returned an invalid response.');
    }

    private function endpoint(): string
    {
        return rtrim((string) config('customcloud.endpoint'), '/');
    }

    private function apiKey(): string
    {
        return (string) config('customcloud.api_key');
    }
}
