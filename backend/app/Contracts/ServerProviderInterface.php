<?php

namespace App\Contracts;

use App\Support\Cloud\ServerOperationFailedException;

/**
 * Port for compute providers (Hetzner, DigitalOcean, a self-hosted gateway…).
 * OMNEX owns the server + operation model and lifecycle; a provider only
 * provisions servers and runs start/stop/reboot/rebuild/delete operations
 * against its platform.
 *
 * Implementations must be side-effect free w.r.t. the OMNEX database — the
 * ServerService is the only writer to the `servers`/`server_operations` tables.
 */
interface ServerProviderInterface
{
    public function name(): string;

    public function label(): string;

    /**
     * Whether the provider has the credentials required to reach a real
     * platform. The sandbox is always configured; real providers activate
     * only once their credentials are set.
     */
    public function isConfigured(): bool;

    /**
     * Verify the configured credentials against the platform WITHOUT
     * provisioning anything or incurring cost (a read-only, authenticated
     * call). Used by the operator to validate tokens before real
     * provisioning. Implementations must never create or mutate resources.
     *
     * @return array{ok: bool, detail?: string}
     */
    public function verify(): array;

    /**
     * Provision a server and return its remote identity, addresses and the
     * current platform status.
     *
     * @return array{provider_server_id: string, ipv4: string, ipv6: ?string, status: string}
     *
     * @throws ServerOperationFailedException
     */
    public function provision(string $name, string $region, string $plan, string $image, string $sshKey): array;

    /**
     * Power the server on. The provider may treat this as a no-op when the
     * server is already running.
     *
     * @throws ServerOperationFailedException
     */
    public function start(string $providerServerId): void;

    /**
     * Power the server off.
     *
     * @throws ServerOperationFailedException
     */
    public function stop(string $providerServerId): void;

    /**
     * Reboot the server (restart the OS).
     *
     * @throws ServerOperationFailedException
     */
    public function reboot(string $providerServerId): void;

    /**
     * Reinstall the server from the given image and return its (possibly new)
     * addresses.
     *
     * @return array{ipv4: string, ipv6: ?string}
     *
     * @throws ServerOperationFailedException
     */
    public function rebuild(string $providerServerId, string $image): array;

    /**
     * Sample current resource usage. The sandbox and real providers without a
     * wired aggregation return deterministic synthetic samples; the custom
     * gateway forwards the request. Bytes for memory/disk, percent for cpu.
     *
     * @return array{cpu: int, memory_used: int, memory_total: int, disk_used: int, disk_total: int}
     */
    public function metrics(string $providerServerId): array;

    /**
     * Create a snapshot (backup) of the server and return its remote identity.
     * The label is a human-readable name shown on the platform.
     *
     * @return array{provider_snapshot_id: string, status: string}
     *
     * @throws ServerOperationFailedException
     */
    public function snapshot(string $providerServerId, string $label): array;

    /**
     * List the snapshots of a server (newest first), as reported by the
     * platform. Used to reconcile the local `server_snapshots` table.
     *
     * @return array<int, array{provider_snapshot_id: string, label: string, status: string, created_at: ?string}>
     */
    public function listSnapshots(string $providerServerId): array;

    /**
     * Delete one snapshot. A 404 (already gone) is treated as success.
     *
     * @throws ServerOperationFailedException
     */
    public function deleteSnapshot(string $providerServerId, string $providerSnapshotId): void;

    /**
     * Permanently destroy the server.
     *
     * @throws ServerOperationFailedException
     */
    public function delete(string $providerServerId): void;

    /**
     * Securely install a public key on an existing server so OMNEX (or the
     * operator) can SSH into it. Only the PUBLIC half is ever sent to the
     * platform. The returned status tells OMNEX what happened:
     *
     *   installed   — the key is now usable on the server
     *   unsupported — the platform cannot add keys to running servers (e.g.
     *                 keys apply only at provisioning/rebuild time); OMNEX
     *                 records this honestly in the operation trail
     *
     * @return array{status: 'installed'|'unsupported', detail?: string}
     *
     * @throws ServerOperationFailedException
     */
    public function installSshKey(string $providerServerId, string $publicKey): array;
}
