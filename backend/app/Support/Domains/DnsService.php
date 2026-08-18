<?php

namespace App\Support\Domains;

use App\Contracts\DnsProviderInterface;
use App\Events\DnsRecordChanged;
use App\Events\DnssecChanged;
use App\Models\DnsHistory;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\User;
use App\Support\Providers\ResolvesTenantProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the DNS zone and record lifecycle. Every mutation is validated, synced
 * to the configured DnsProviderInterface, persisted, written to the immutable
 * history (for rollback) and published as a DnsRecordChanged event.
 */
final class DnsService
{
    use ResolvesTenantProvider;

    public function __construct(private DnsProviderRegistry $providers) {}

    protected function providerConfigKey(): string
    {
        return 'omnex.domain.dns_provider';
    }

    protected function providerSettingsKey(): string
    {
        return 'dns_provider';
    }

    private function provider(): DnsProviderInterface
    {
        return $this->providers->get($this->activeProviderName());
    }

    /**
     * @return array<int, array{name: string, label: string, configured: bool}>
     */
    public function providers(): array
    {
        return $this->providers->all();
    }

    /**
     * @return array<int, DnsRecord>
     */
    public function records(DnsZone $zone): array
    {
        return $zone->records()->orderBy('type')->orderBy('name')->get()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createRecord(DnsZone $zone, array $data, User $user): DnsRecord
    {
        $normalized = $this->validateAndNormalize($data);

        $remote = $this->provider()->createRecord($zone->domain->name, $normalized);

        $record = $zone->records()->create(array_merge($normalized, [
            'external_id' => $remote['external_id'] ?? null,
        ]));

        $this->logHistory($zone, $record->id, 'created', null, $this->snapshot($record), $user);

        DnsRecordChanged::dispatch($zone, 'created', $record, null, $this->snapshot($record));

        return $record;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateRecord(DnsZone $zone, DnsRecord $record, array $data, User $user): DnsRecord
    {
        $normalized = $this->validateAndNormalize($data);

        $before = $this->snapshot($record);

        $this->provider()->updateRecord($zone->domain->name, $normalized);

        $record->update($normalized);
        $record->refresh();

        $this->logHistory($zone, $record->id, 'updated', $before, $this->snapshot($record), $user);

        DnsRecordChanged::dispatch($zone, 'updated', $record, $before, $this->snapshot($record));

        return $record;
    }

    public function deleteRecord(DnsZone $zone, DnsRecord $record, User $user): void
    {
        $before = $this->snapshot($record);

        $this->provider()->deleteRecord($zone->domain->name, $before);

        $record->delete();

        $this->logHistory($zone, null, 'deleted', $before, null, $user);

        DnsRecordChanged::dispatch($zone, 'deleted', null, $before, null);
    }

    /**
     * @return array<int, DnsHistory>
     */
    public function history(DnsZone $zone): array
    {
        return $zone->history()
            ->with('user')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->all();
    }

    /**
     * Roll a change back and record the inverse operation, so history stays a
     * linear, fully reversible log.
     */
    public function rollback(DnsZone $zone, DnsHistory $entry, User $user): void
    {
        match ($entry->action) {
            'created' => $this->rollbackCreated($zone, $entry, $user),
            'updated' => $this->rollbackUpdated($zone, $entry, $user),
            'deleted' => $this->rollbackDeleted($zone, $entry, $user),
            'imported' => $this->rollbackImported($zone, $entry, $user),
            default => throw ValidationException::withMessages(['history' => ["Cannot roll back action [{$entry->action}]."]]),
        };
    }

    public function applyTemplate(DnsZone $zone, string $template, User $user): array
    {
        $records = DnsTemplates::get($template);

        if ($records === []) {
            throw ValidationException::withMessages(['template' => ["Unknown DNS template [{$template}]."]]);
        }

        $created = [];

        DB::transaction(function () use ($zone, $records, $user, &$created) {
            foreach ($records as $data) {
                $created[] = $this->createRecord($zone, $data, $user);
            }
        });

        return $created;
    }

    /**
     * @return array{enabled: bool, status: string, ds_records: array<int, array<string, mixed>>}
     */
    public function dnssec(DnsZone $zone): array
    {
        return [
            'enabled' => $zone->dnssec_enabled,
            'status' => $zone->dnssec_status,
            'ds_records' => $zone->dnssec_ds_records ?? [],
        ];
    }

    /**
     * Sign the zone and capture the DS record(s) to publish at the registrar.
     *
     * @return array{enabled: bool, status: string, ds_records: array<int, array<string, mixed>>}
     */
    public function enableDnssec(DnsZone $zone): array
    {
        if ($zone->dnssec_enabled) {
            throw ValidationException::withMessages(['dnssec' => ['DNSSEC is already enabled on this zone.']]);
        }

        $dsRecords = $this->provider()->enableDnssec($zone->domain->name);

        $zone->update([
            'dnssec_enabled' => true,
            'dnssec_status' => 'active',
            'dnssec_ds_records' => $dsRecords,
        ]);

        DnssecChanged::dispatch($zone, 'enabled', null, $dsRecords);

        return $this->dnssec($zone);
    }

    /**
     * Stop signing the zone and clear its DS records.
     *
     * @return array{enabled: bool, status: string, ds_records: array<int, array<string, mixed>>}
     */
    public function disableDnssec(DnsZone $zone): array
    {
        if (! $zone->dnssec_enabled) {
            throw ValidationException::withMessages(['dnssec' => ['DNSSEC is not enabled on this zone.']]);
        }

        $before = $zone->dnssec_ds_records;

        $this->provider()->disableDnssec($zone->domain->name);

        $zone->update([
            'dnssec_enabled' => false,
            'dnssec_status' => 'unsigned',
            'dnssec_ds_records' => null,
        ]);

        DnssecChanged::dispatch($zone, 'disabled', $before, null);

        return $this->dnssec($zone);
    }

    public function export(DnsZone $zone): string
    {
        $records = collect($this->records($zone))
            ->map(fn (DnsRecord $record) => $this->snapshot($record))
            ->all();

        return ZoneFileParser::export($zone->domain->name, $records);
    }

    /**
     * Replace the zone with the parsed contents of a BIND zone file.
     *
     * @return array<int, DnsRecord>
     */
    public function import(DnsZone $zone, string $zoneFile, User $user): array
    {
        $incoming = ZoneFileParser::parse($zoneFile, $zone->domain->name);

        $errors = [];
        foreach ($incoming as $data) {
            $errors = [...$errors, ...DnsValidator::validate($data)];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['zone_file' => $errors]);
        }

        $before = collect($this->records($zone))
            ->map(fn (DnsRecord $record) => $this->snapshot($record))
            ->all();

        $created = $this->replaceRecords($zone, $incoming);

        $this->logHistory($zone, null, 'imported', $before, collect($created)->map(fn ($r) => $this->snapshot($r))->all(), $user);

        DnsRecordChanged::dispatch($zone, 'imported', null, ['count' => count($before)], ['count' => count($created)]);

        return $created;
    }

    private function rollbackCreated(DnsZone $zone, DnsHistory $entry, User $user): void
    {
        $record = $entry->record_id !== null ? DnsRecord::find($entry->record_id) : null;

        if ($record === null) {
            return;
        }

        $snapshot = $this->snapshot($record);

        $this->provider()->deleteRecord($zone->domain->name, $snapshot);
        $record->delete();

        $this->logHistory($zone, null, 'deleted', $snapshot, null, $user);

        DnsRecordChanged::dispatch($zone, 'rolled_back', null, $snapshot, null);
    }

    private function rollbackUpdated(DnsZone $zone, DnsHistory $entry, User $user): void
    {
        $record = $entry->record_id !== null ? DnsRecord::find($entry->record_id) : null;

        if ($record === null || $entry->before === null) {
            return;
        }

        $after = $this->snapshot($record);

        $this->provider()->updateRecord($zone->domain->name, $entry->before);

        $record->update($entry->before);
        $record->refresh();

        $this->logHistory($zone, $record->id, 'updated', $after, $entry->before, $user);

        DnsRecordChanged::dispatch($zone, 'rolled_back', $record, $after, $entry->before);
    }

    private function rollbackDeleted(DnsZone $zone, DnsHistory $entry, User $user): void
    {
        if ($entry->before === null) {
            return;
        }

        $record = $this->restoreSnapshot($zone, $entry->before);

        $this->logHistory($zone, $record->id, 'created', null, $entry->before, $user);

        DnsRecordChanged::dispatch($zone, 'rolled_back', $record, null, $entry->before);
    }

    private function rollbackImported(DnsZone $zone, DnsHistory $entry, User $user): void
    {
        $before = $entry->before ?? [];

        $current = collect($this->records($zone))->map(fn (DnsRecord $r) => $this->snapshot($r))->all();

        $this->replaceRecords($zone, $before);

        $this->logHistory($zone, null, 'imported', $current, $before, $user);

        DnsRecordChanged::dispatch($zone, 'rolled_back', null, ['count' => count($current)], ['count' => count($before)]);
    }

    /**
     * Delete every record in the zone and recreate from the given snapshots.
     *
     * @param  array<int, array<string, mixed>>  $snapshots
     * @return array<int, DnsRecord>
     */
    private function replaceRecords(DnsZone $zone, array $snapshots): array
    {
        return DB::transaction(function () use ($zone, $snapshots) {
            foreach ($this->records($zone) as $record) {
                $this->provider()->deleteRecord($zone->domain->name, $this->snapshot($record));
                $record->delete();
            }

            $created = [];
            foreach ($snapshots as $snapshot) {
                $created[] = $this->restoreSnapshot($zone, $snapshot);
            }

            return $created;
        });
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function restoreSnapshot(DnsZone $zone, array $snapshot): DnsRecord
    {
        $data = $this->validateAndNormalize($snapshot);

        $remote = $this->provider()->createRecord($zone->domain->name, $data);

        return $zone->records()->create(array_merge($data, [
            'external_id' => $remote['external_id'] ?? null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{type: string, name: string, content: string, ttl: int, priority: ?int, proxied: bool}
     */
    private function validateAndNormalize(array $data): array
    {
        $errors = DnsValidator::validate($data);

        if ($errors !== []) {
            throw ValidationException::withMessages(['record' => $errors]);
        }

        return $this->normalize($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{type: string, name: string, content: string, ttl: int, priority: ?int, proxied: bool}
     */
    private function normalize(array $data): array
    {
        $type = strtoupper(trim((string) ($data['type'] ?? '')));

        return [
            'type' => $type,
            'name' => trim((string) ($data['name'] ?? '@')) !== '' ? trim((string) ($data['name'] ?? '@')) : '@',
            'content' => trim((string) ($data['content'] ?? '')),
            'ttl' => (int) ($data['ttl'] ?? 3600),
            'priority' => isset($data['priority']) && $data['priority'] !== '' && $data['priority'] !== null ? (int) $data['priority'] : null,
            'proxied' => (bool) ($data['proxied'] ?? false),
        ];
    }

    /**
     * @return array{id: string, type: string, name: string, content: string, ttl: int, priority: ?int, proxied: bool}
     */
    private function snapshot(DnsRecord $record): array
    {
        return [
            'id' => $record->id,
            'type' => $record->type,
            'name' => $record->name,
            'content' => $record->content,
            'ttl' => $record->ttl,
            'priority' => $record->priority,
            'proxied' => $record->proxied,
        ];
    }

    private function logHistory(
        DnsZone $zone,
        ?string $recordId,
        string $action,
        ?array $before,
        ?array $after,
        User $user,
    ): DnsHistory {
        return $zone->history()->create([
            'record_id' => $recordId,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'user_id' => $user->id,
        ]);
    }
}
