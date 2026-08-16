<?php

namespace App\Support\Cloud\Providers;

use App\Contracts\ServerProviderInterface;
use App\Support\Cloud\ServerOperationFailedException;
use Illuminate\Support\Str;

/**
 * Deterministic in-memory compute platform for local/test environments.
 * Nothing is ever provisioned anywhere — addresses and ids are derived from a
 * stable hash of the inputs, never random, so tests and the UI are
 * reproducible.
 *
 * Failure is deterministic: a server named "fail" makes every operation on it
 * fail (and provisioning such a server fails too), which lets ServerService
 * exercise its operation-failure path.
 */
final class SandboxServerProvider implements ServerProviderInterface
{
    use GeneratesSyntheticMetrics;

    public function name(): string
    {
        return 'sandbox';
    }

    public function label(): string
    {
        return 'Sandbox';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function verify(): array
    {
        return ['ok' => true, 'detail' => 'Sandbox is always available.'];
    }

    public function provision(string $name, string $region, string $plan, string $image, string $sshKey): array
    {
        if ($name === 'fail') {
            throw new ServerOperationFailedException(
                'Provisioning failed: name "fail" is a deterministic failure trigger.'
            );
        }

        $seed = substr(hash('sha256', $name.':'.$region.':'.$plan.':'.$image), 0, 8);

        // The name is carried in the id so the operation guard below can
        // trigger deterministically on servers whose name contains "fail".
        return [
            'provider_server_id' => 'sbox-srv-'.Str::slug($name).'-'.$seed,
            'ipv4' => '10.'.(hexdec(substr($seed, 0, 2)) % 250 + 1).'.'
                .(hexdec(substr($seed, 2, 2)) % 250 + 1).'.'
                .(hexdec(substr($seed, 4, 2)) % 250 + 1),
            'ipv6' => null,
            'status' => 'running',
        ];
    }

    public function start(string $providerServerId): void
    {
        $this->guard($providerServerId);
    }

    public function stop(string $providerServerId): void
    {
        $this->guard($providerServerId);
    }

    public function reboot(string $providerServerId): void
    {
        $this->guard($providerServerId);
    }

    public function rebuild(string $providerServerId, string $image): array
    {
        $this->guard($providerServerId);

        return [
            'ipv4' => '10.'.(hexdec(substr($providerServerId, -8, 2)) % 250 + 1).'.'
                .(hexdec(substr($providerServerId, -6, 2)) % 250 + 1).'.'
                .(hexdec(substr($providerServerId, -4, 2)) % 250 + 1),
            'ipv6' => null,
        ];
    }

    public function delete(string $providerServerId): void
    {
        $this->guard($providerServerId);
    }

    public function metrics(string $providerServerId): array
    {
        return $this->sampleSyntheticMetrics($providerServerId);
    }

    /** @var array<string, array<int, array{provider_snapshot_id: string, label: string, status: string, created_at: string}>> */
    private static array $snapshots = [];

    /** @var array<string, array<int, string>> installed public keys per server id */
    private static array $installedKeys = [];

    public function snapshot(string $providerServerId, string $label): array
    {
        $this->guard($providerServerId);

        $id = 'sbox-snap-'.substr(hash('sha256', $providerServerId.':'.$label.':'.count(self::$snapshots[$providerServerId] ?? [])), 0, 8);

        self::$snapshots[$providerServerId][] = [
            'provider_snapshot_id' => $id,
            'label' => $label,
            'status' => 'available',
            'created_at' => now()->toIso8601String(),
        ];

        return ['provider_snapshot_id' => $id, 'status' => 'available'];
    }

    public function listSnapshots(string $providerServerId): array
    {
        $this->guard($providerServerId);

        return array_reverse(self::$snapshots[$providerServerId] ?? []);
    }

    public function deleteSnapshot(string $providerServerId, string $providerSnapshotId): void
    {
        $this->guard($providerServerId);

        self::$snapshots[$providerServerId] = array_values(array_filter(
            self::$snapshots[$providerServerId] ?? [],
            fn (array $snapshot) => $snapshot['provider_snapshot_id'] !== $providerSnapshotId,
        ));
    }

    public function installSshKey(string $providerServerId, string $publicKey): array
    {
        $this->guard($providerServerId);

        self::$installedKeys[$providerServerId][] = $publicKey;

        return [
            'status' => 'installed',
            'detail' => 'Key installed on the sandbox server.',
        ];
    }

    /**
     * Public keys installed on the server (test helper).
     *
     * @return array<int, string>
     */
    public static function installedKeys(string $providerServerId): array
    {
        return self::$installedKeys[$providerServerId] ?? [];
    }

    private function guard(string $providerServerId): void
    {
        if (Str::contains($providerServerId, 'fail')) {
            throw new ServerOperationFailedException(
                'Operation failed: the server id contains the deterministic failure trigger "fail".'
            );
        }
    }
}
