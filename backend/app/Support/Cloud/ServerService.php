<?php

namespace App\Support\Cloud;

use App\Contracts\ServerProviderInterface;
use App\Models\Membership;
use App\Models\Server;
use App\Models\ServerMetricSample;
use App\Models\ServerOperation;
use App\Models\ServerSnapshot;
use App\Models\SshKey;
use App\Support\Audit\AuditLogger;
use App\Support\Notifications\NotificationService;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Owns the OMNEX Cloud lifecycle: provision, start/stop/reboot/rebuild and
 * deletion. OMNEX is the system of record for servers and operations; a
 * ServerProviderInterface only performs platform operations. Every mutation is
 * audited and every power operation leaves a server_operations trail.
 */
final class ServerService
{
    public function __construct(private ServerProviderRegistry $providers) {}

    /**
     * @return array<int, array{name: string, label: string, configured: bool}>
     */
    public function providers(): array
    {
        return $this->providers->all();
    }

    private function provider(?string $name = null): ServerProviderInterface
    {
        $provider = $this->providers->get($name ?? $this->providers->get()->name());

        if (! $provider->isConfigured()) {
            throw new ServerProviderException("The [{$provider->label()}] cloud provider is not configured.");
        }

        return $provider;
    }

    /**
     * @param  array<int, string>  $tags
     */
    public function create(
        string $name,
        string $region,
        string $plan,
        string $image,
        string $sshKey = '',
        array $tags = [],
        ?string $providerName = null,
        ?string $sshKeyId = null,
    ): Server {
        $name = $this->validateName($name);
        $region = $this->validateRegion($region);
        $plan = $this->validatePlan($plan);
        $image = $this->validateImage($image);
        $tags = $this->validateTags($tags);

        // A saved key wins over a raw pasted key: resolve it (tenant-scoped)
        // and snapshot its body, which is what the provider receives.
        $resolvedKey = null;

        if ($sshKeyId !== null && $sshKeyId !== '') {
            $resolvedKey = SshKey::findOrFail($sshKeyId);
            $sshKey = $resolvedKey->public_key;
        }

        $provider = $this->provider($providerName);

        try {
            $result = $provider->provision($name, $region, $plan, $image, $sshKey);
        } catch (ServerOperationFailedException $e) {
            // The server never exists locally, so there is no operation row to
            // persist — surface the upstream rejection as a provider failure.
            throw new ServerProviderException($e->getMessage());
        }

        $server = Server::create([
            'name' => $name,
            'region' => $result['region'] ?? $region,
            'plan' => $result['plan'] ?? $plan,
            'image' => $result['image'] ?? $image,
            'provider' => $provider->name(),
            'provider_server_id' => $result['provider_server_id'],
            'status' => $result['status'] ?? 'provisioning',
            'ipv4' => $result['ipv4'] ?: null,
            'ipv6' => $result['ipv6'] ?? null,
            'ssh_key' => $sshKey !== '' ? $sshKey : null,
            'ssh_key_id' => $resolvedKey?->id ?? null,
            'tags' => $tags,
        ]);

        ServerOperation::create([
            'server_id' => $server->id,
            'type' => 'provision',
            'status' => 'succeeded',
            'started_at' => now(),
            'completed_at' => now(),
            'result' => $server->ipv4 ?? '',
        ]);

        AuditLogger::record('server.created', 'server', $server->id, null, [
            'name' => $server->name,
            'region' => $server->region,
            'plan' => $server->plan,
            'image' => $server->image,
            'provider' => $server->provider,
        ]);

        return $server;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Server $server, array $input): Server
    {
        $before = $this->snapshot($server);
        $updates = [];

        if (array_key_exists('name', $input)) {
            $updates['name'] = $this->validateName((string) $input['name']);
        }

        if (array_key_exists('ssh_key', $input)) {
            $updates['ssh_key'] = trim((string) $input['ssh_key']) !== '' ? trim((string) $input['ssh_key']) : null;
        }

        if (array_key_exists('tags', $input)) {
            $updates['tags'] = $this->validateTags((array) $input['tags']);
        }

        if (array_key_exists('snapshot_frequency', $input)) {
            $updates['snapshot_frequency'] = $this->validateSnapshotFrequency((string) $input['snapshot_frequency']);
        }

        if (array_key_exists('snapshot_retention_days', $input)) {
            $updates['snapshot_retention_days'] = max(1, min(365, (int) $input['snapshot_retention_days']));
        }

        if ($updates !== []) {
            $server->update($updates);
        }

        AuditLogger::record('server.updated', 'server', $server->id, $before, $this->snapshot($server));

        return $server->fresh();
    }

    public function start(Server $server): ServerOperation
    {
        return $this->runOperation($server, 'start', 'running', fn () => $this->provider($server->provider)->start($server->provider_server_id ?? ''));
    }

    public function stop(Server $server): ServerOperation
    {
        return $this->runOperation($server, 'stop', 'stopped', fn () => $this->provider($server->provider)->stop($server->provider_server_id ?? ''));
    }

    public function reboot(Server $server): ServerOperation
    {
        return $this->runOperation($server, 'reboot', 'running', fn () => $this->provider($server->provider)->reboot($server->provider_server_id ?? ''));
    }

    public function rebuild(Server $server, string $image): ServerOperation
    {
        $image = $this->validateImage($image);

        return $this->runOperation($server, 'rebuild', 'running', function () use ($server, $image) {
            $result = $this->provider($server->provider)->rebuild($server->provider_server_id ?? '', $image);

            $server->update([
                'image' => $image,
                'ipv4' => $result['ipv4'] !== '' ? $result['ipv4'] : $server->ipv4,
                'ipv6' => $result['ipv6'] ?? $server->ipv6,
            ]);

            return $result;
        });
    }

    /**
     * Securely copy a saved key onto an existing server through the provider.
     * Only the PUBLIC half ever leaves OMNEX. The provider may report
     * `unsupported` (keys apply at provisioning/rebuild time on some
     * platforms); that outcome is recorded honestly in the operation trail.
     *
     * @return array{status: 'installed'|'unsupported', detail?: ?string}
     */
    public function installSshKey(Server $server, SshKey $key): array
    {
        $provider = $this->provider($server->provider);

        $operation = ServerOperation::create([
            'server_id' => $server->id,
            'type' => 'install_key',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $result = $provider->installSshKey($server->provider_server_id ?? '', $key->public_key);

            $operation->update([
                'status' => 'succeeded',
                'completed_at' => now(),
                'result' => json_encode($result),
            ]);

            $server->update([
                'ssh_key' => $key->public_key,
                'ssh_key_id' => $key->id,
            ]);

            AuditLogger::record('server.ssh_key_installed', 'server', $server->id, null, [
                'name' => $server->name,
                'ssh_key' => $key->fingerprint,
                'status' => $result['status'] ?? 'installed',
            ]);

            return $result;
        } catch (ServerOperationFailedException $e) {
            $operation->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error' => $e->getMessage(),
            ]);

            AuditLogger::record('server.ssh_key_install_failed', 'server', $server->id, null, [
                'name' => $server->name,
                'ssh_key' => $key->fingerprint,
                'error' => $e->getMessage(),
            ], 'error');

            throw new ServerProviderException($e->getMessage());
        }
    }

    public function delete(Server $server): void
    {
        $before = $this->snapshot($server);

        try {
            $this->provider($server->provider)->delete($server->provider_server_id ?? '');
        } catch (ServerOperationFailedException $e) {
            throw new ServerProviderException($e->getMessage());
        }

        $server->operations()->delete();
        $server->snapshots()->delete();
        $server->delete();

        AuditLogger::record('server.deleted', 'server', null, $before, null);
    }

    /**
     * @return array{cpu: int, memory_used: int, memory_total: int, disk_used: int, disk_total: int}
     */
    public function metrics(Server $server): array
    {
        return $this->provider($server->provider)->metrics($server->provider_server_id ?? '');
    }

    /**
     * Persist one sample so the metrics history endpoint can serve it.
     * Called by the SSE stream for every emitted frame.
     *
     * @param  array{cpu: int, memory_used: int, memory_total: int, disk_used: int, disk_total: int}  $metrics
     */
    public function recordMetricsSample(Server $server, array $metrics): ServerMetricSample
    {
        $sample = ServerMetricSample::create([
            'server_id' => $server->id,
            'cpu' => $metrics['cpu'],
            'memory_used' => $metrics['memory_used'],
            'memory_total' => $metrics['memory_total'],
            'disk_used' => $metrics['disk_used'],
            'disk_total' => $metrics['disk_total'],
            'sampled_at' => now(),
        ]);

        $this->checkMetricsThresholds($server, $metrics);

        return $sample;
    }

    /**
     * Evaluate one sample against the configured usage thresholds and raise an
     * OMNEX notification (type `server.alert`) when a limit is crossed.
     * Cooldown is per metric per server (`omnex.cloud.alerts.cooldown_seconds`),
     * tracked in `servers.alert_suppressed_at`, so a sustained overload does
     * not spam the notification feed.
     *
     * @param  array{cpu: int, memory_used: int, memory_total: int, disk_used: int, disk_total: int}  $metrics
     */
    public function checkMetricsThresholds(Server $server, array $metrics): void
    {
        $thresholds = config('omnex.cloud.alerts');
        $cooldown = max(0, (int) ($thresholds['cooldown_seconds'] ?? 3600));

        $usage = [
            'cpu' => (int) round($metrics['cpu']),
            'memory' => $metrics['memory_total'] > 0
                ? (int) round($metrics['memory_used'] / $metrics['memory_total'] * 100)
                : 0,
            'disk' => $metrics['disk_total'] > 0
                ? (int) round($metrics['disk_used'] / $metrics['disk_total'] * 100)
                : 0,
        ];

        $breaches = [];
        $suppressed = $server->alert_suppressed_at ?? [];
        $now = Carbon::now();

        foreach ($usage as $metric => $percent) {
            $limit = (int) ($thresholds[$metric] ?? 90);

            if ($percent < $limit) {
                continue;
            }

            $lastAlert = isset($suppressed[$metric]) ? Carbon::parse($suppressed[$metric]) : null;

            if ($lastAlert !== null && $lastAlert->greaterThan($now->copy()->subSeconds($cooldown))) {
                continue;
            }

            $suppressed[$metric] = $now->toIso8601String();
            $breaches[] = [
                'metric' => $metric,
                'percent' => $percent,
                'limit' => $limit,
            ];
        }

        if ($breaches === []) {
            return;
        }

        $server->update(['alert_suppressed_at' => $suppressed]);

        $labels = implode(', ', array_map(
            fn (array $breach) => "{$breach['metric']} at {$breach['percent']}% (limit {$breach['limit']}%)",
            $breaches,
        ));

        Membership::query()
            ->where('organization_id', $server->organization_id)
            ->where('status', 'active')
            ->whereHas('role.permissions', fn ($query) => $query->where('key', 'cloud.read'))
            ->pluck('user_id')
            ->each(fn (string $userId) => NotificationService::send(
                userId: $userId,
                type: 'server.alert',
                title: "High resource usage on {$server->name}",
                body: $labels,
                data: [
                    'server_id' => $server->id,
                    'server_name' => $server->name,
                    'metrics' => $usage,
                    'route' => '/cloud',
                ],
                organizationId: $server->organization_id,
                severity: 'warning',
            ));
    }

    /**
     * Most recent persisted samples, oldest first (ready for a sparkline).
     *
     * @return array<int, ServerMetricSample>
     */
    public function metricsHistory(Server $server, int $limit): array
    {
        return $server->metricSamples()
            ->orderByDesc('sampled_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->all();
    }

    /**
     * Create a snapshot of the server (manual or scheduled). The platform
     * snapshot is recorded locally in `server_snapshots` and a `snapshot`
     * operation is appended to the trail. Returns the local snapshot record.
     */
    public function createSnapshot(Server $server, ?string $label = null): ServerSnapshot
    {
        $label = trim($label ?? '');

        if ($label === '') {
            $label = 'snapshot-'.now()->format('Ymd-His');
        }

        $provider = $this->provider($server->provider);
        $providerSnapshotId = '';
        $status = 'available';
        $createdAt = now();

        $operation = ServerOperation::create([
            'server_id' => $server->id,
            'type' => 'snapshot',
            'status' => 'running',
            'started_at' => $createdAt,
        ]);

        try {
            $result = $provider->snapshot($server->provider_server_id ?? '', $label);
            $providerSnapshotId = (string) ($result['provider_snapshot_id'] ?? '');
            $status = (string) ($result['status'] ?? 'available');

            $operation->update([
                'status' => 'succeeded',
                'completed_at' => now(),
                'result' => $providerSnapshotId,
            ]);
        } catch (ServerOperationFailedException $e) {
            $operation->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error' => $e->getMessage(),
            ]);

            AuditLogger::record('server.snapshot_failed', 'server', $server->id, null, [
                'error' => $e->getMessage(),
            ], 'error');

            throw new ServerProviderException($e->getMessage());
        }

        $snapshot = ServerSnapshot::create([
            'organization_id' => $server->organization_id,
            'server_id' => $server->id,
            'provider_snapshot_id' => $providerSnapshotId,
            'label' => $label,
            'status' => $status,
            'created_at' => $createdAt,
        ]);

        $server->update(['last_snapshot_at' => $createdAt]);

        AuditLogger::record('server.snapshot_created', 'server', $server->id, null, [
            'snapshot' => $snapshot->id,
            'label' => $label,
        ]);

        return $snapshot;
    }

    /**
     * @return array<int, ServerSnapshot>
     */
    public function snapshots(Server $server): array
    {
        return $server->snapshots()->get()->all();
    }

    public function deleteSnapshot(Server $server, ServerSnapshot $snapshot): void
    {
        $this->provider($server->provider)
            ->deleteSnapshot($server->provider_server_id ?? '', $snapshot->provider_snapshot_id);

        $snapshot->delete();

        AuditLogger::record('server.snapshot_deleted', 'server', $server->id, null, [
            'snapshot' => $snapshot->id,
            'label' => $snapshot->label,
        ]);
    }

    /**
     * Servers whose schedule says a new snapshot is due now.
     *
     * @return array<int, Server>
     */
    public function serversDueForSnapshot(): array
    {
        $now = Carbon::now();

        return Server::query()
            ->whereNot('snapshot_frequency', 'disabled')
            ->get()
            ->filter(fn (Server $server) => $this->snapshotDue($server, $now))
            ->values()
            ->all();
    }

    /**
     * Scheduled backups for every due server, then retention enforcement.
     *
     * @return array{created: int, deleted: int}
     */
    public function runScheduledSnapshots(): array
    {
        $created = 0;

        foreach ($this->serversDueForSnapshot() as $server) {
            $this->createSnapshot($server);
            $created++;
        }

        $deleted = $this->applyRetention();

        return ['created' => $created, 'deleted' => $deleted];
    }

    /**
     * Delete snapshots older than their server's retention window. Returns
     * the number of snapshots removed locally (and on the platform).
     */
    public function applyRetention(): int
    {
        $deleted = 0;

        foreach ($this->expiredSnapshots() as $snapshot) {
            $server = $snapshot->server;

            if ($server === null) {
                continue;
            }

            try {
                $this->provider($server->provider)
                    ->deleteSnapshot($server->provider_server_id ?? '', $snapshot->provider_snapshot_id);
            } catch (ServerProviderException|ServerOperationFailedException $e) {
                AuditLogger::record('server.snapshot_retention_failed', 'server', $server->id, null, [
                    'snapshot' => $snapshot->id,
                    'error' => $e->getMessage(),
                ], 'error');

                continue;
            }

            $snapshot->delete();
            $deleted++;
        }

        return $deleted;
    }

    /**
     * @return array<int, ServerSnapshot>
     */
    public function expiredSnapshots(): array
    {
        $now = Carbon::now();
        $expired = [];

        foreach (ServerSnapshot::all() as $snapshot) {
            $server = $snapshot->server;

            if ($server === null) {
                continue;
            }

            $retention = max(1, (int) $server->snapshot_retention_days);

            if ($snapshot->created_at !== null && $snapshot->created_at->lessThan($now->copy()->subDays($retention))) {
                $expired[] = $snapshot;
            }
        }

        return $expired;
    }

    /**
     * Reconcile the local snapshot table with the platform's listing.
     *
     * @return array<int, ServerSnapshot>
     */
    public function reconcileSnapshots(Server $server): array
    {
        $remote = $this->provider($server->provider)->listSnapshots($server->provider_server_id ?? '');
        $known = $server->snapshots()->get()->keyBy('provider_snapshot_id');

        foreach ($remote as $item) {
            $providerId = (string) ($item['provider_snapshot_id'] ?? '');

            if ($providerId === '' || $known->has($providerId)) {
                continue;
            }

            $snapshot = ServerSnapshot::create([
                'organization_id' => $server->organization_id,
                'server_id' => $server->id,
                'provider_snapshot_id' => $providerId,
                'label' => (string) ($item['label'] ?? $providerId),
                'status' => (string) ($item['status'] ?? 'available'),
                'created_at' => isset($item['created_at']) ? Carbon::parse($item['created_at']) : null,
            ]);

            $known->put($providerId, $snapshot);
        }

        return $known->sortByDesc('created_at')->values()->all();
    }

    private function snapshotDue(Server $server, Carbon $now): bool
    {
        if (! $server->snapshotEnabled()) {
            return false;
        }

        $last = $server->last_snapshot_at;

        if ($last === null) {
            return true;
        }

        $interval = $server->snapshot_frequency === 'weekly' ? 7 : 1;

        return $last->lessThan($now->copy()->subDays($interval));
    }

    private function validateSnapshotFrequency(string $frequency): string
    {
        $frequency = strtolower(trim($frequency));

        if (! in_array($frequency, ['disabled', 'daily', 'weekly'], true)) {
            throw ValidationException::withMessages(['snapshot_frequency' => ['The snapshot frequency must be disabled, daily or weekly.']]);
        }

        return $frequency;
    }

    /**
     * @return array<int, ServerOperation>
     */
    public function operations(Server $server): array
    {
        return $server->operations()->get()->all();
    }

    private function runOperation(Server $server, string $type, string $successStatus, Closure $run): ServerOperation
    {
        $operation = ServerOperation::create([
            'server_id' => $server->id,
            'type' => $type,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $result = $run();

            $operation->update([
                'status' => 'succeeded',
                'completed_at' => now(),
                'result' => is_array($result) ? json_encode($result) : null,
            ]);

            $server->update(['status' => $successStatus]);

            AuditLogger::record("server.{$type}", 'server', $server->id, null, [
                'name' => $server->name,
                'status' => $successStatus,
            ]);
        } catch (ServerOperationFailedException $e) {
            $operation->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error' => $e->getMessage(),
            ]);

            AuditLogger::record("server.{$type}_failed", 'server', $server->id, null, [
                'error' => $e->getMessage(),
            ], 'error');
        }

        return $operation->fresh();
    }

    private function validateName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['The name is required.']]);
        }

        if (mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => ['The name must not exceed 255 characters.']]);
        }

        if (! preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $name)) {
            throw ValidationException::withMessages(['name' => ['The name must contain only lowercase letters, numbers and dashes.']]);
        }

        return $name;
    }

    private function validateRegion(string $region): string
    {
        $region = strtolower(trim($region));

        if ($region === '') {
            $region = (string) config('omnex.cloud.default_region', 'fsn1');
        }

        if (! in_array($region, config('omnex.cloud.regions', ['fsn1']), true)) {
            throw ValidationException::withMessages(['region' => ['The region is not supported.']]);
        }

        return $region;
    }

    private function validatePlan(string $plan): string
    {
        $plan = strtolower(trim($plan));

        if ($plan === '') {
            $plan = (string) config('omnex.cloud.default_plan', 'cpx11');
        }

        if (! in_array($plan, config('omnex.cloud.plans', ['cpx11']), true)) {
            throw ValidationException::withMessages(['plan' => ['The plan is not supported.']]);
        }

        return $plan;
    }

    private function validateImage(string $image): string
    {
        $image = strtolower(trim($image));

        if ($image === '') {
            $image = (string) config('omnex.cloud.default_image', 'ubuntu-24.04');
        }

        if (! in_array($image, config('omnex.cloud.images', ['ubuntu-24.04']), true)) {
            throw ValidationException::withMessages(['image' => ['The image is not supported.']]);
        }

        return $image;
    }

    /**
     * @param  array<int, string>  $tags
     * @return array<int, string>
     */
    private function validateTags(array $tags): array
    {
        $clean = [];

        foreach ($tags as $tag) {
            $tag = trim((string) $tag);

            if ($tag === '' || mb_strlen($tag) > 32 || preg_match('/\s/', $tag)) {
                throw ValidationException::withMessages(['tags' => ['Each tag must be a single word of at most 32 characters.']]);
            }

            $clean[] = $tag;
        }

        return array_values(array_unique($clean));
    }

    /**
     * @return array{name: string, region: string, plan: string, image: string, status: string, ipv4: ?string, ipv6: ?string}
     */
    private function snapshot(Server $server): array
    {
        return [
            'name' => $server->name,
            'region' => $server->region,
            'plan' => $server->plan,
            'image' => $server->image,
            'status' => $server->status,
            'ipv4' => $server->ipv4,
            'ipv6' => $server->ipv6,
        ];
    }
}
