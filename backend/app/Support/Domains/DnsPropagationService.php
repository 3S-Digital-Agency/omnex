<?php

namespace App\Support\Domains;

use App\Contracts\DnsPropagationCheckerInterface;
use App\Models\DnsPropagationCheck;
use App\Models\DnsRecord;
use App\Models\DnsZone;

/**
 * Compares each record in a zone against every authoritative nameserver via
 * the configured DnsPropagationCheckerInterface. Results are persisted as an
 * append-only history; the status endpoint returns the latest check per
 * (nameserver, record).
 */
final class DnsPropagationService
{
    public function __construct(private DnsPropagationCheckerInterface $checker) {}

    /**
     * @return array{domain: string, nameservers: array<int, string>, checked_at: ?string, data: array<int, DnsPropagationCheck>, summary: array{synced: int, pending: int, outdated: int, error: int, total: int}}
     */
    public function status(DnsZone $zone): array
    {
        $latest = $zone->propagationChecks()
            ->orderByDesc('checked_at')
            ->get()
            ->groupBy(fn (DnsPropagationCheck $check) => $check->nameserver.'#'.$check->record_type.'#'.$check->record_name)
            ->map(fn ($group) => $group->first())
            ->values()
            ->sortBy(fn (DnsPropagationCheck $check) => [$check->nameserver, $check->record_name])
            ->values();

        return $this->buildStatus($zone, $latest->all());
    }

    /**
     * Run a fresh per-nameserver check and persist the results.
     *
     * @return array{domain: string, nameservers: array<int, string>, checked_at: ?string, data: array<int, DnsPropagationCheck>, summary: array{synced: int, pending: int, outdated: int, error: int, total: int}}
     */
    public function check(DnsZone $zone): array
    {
        $domain = $zone->domain;
        $nameservers = $domain->nameservers ?: config('nexus.domain.default_nameservers', ['ns1.omnex.io', 'ns2.omnex.io']);

        $expected = $zone->records()
            ->get()
            ->map(fn (DnsRecord $record) => [
                'type' => $record->type,
                'name' => $record->name,
                'content' => $record->content,
                'ttl' => $record->ttl,
                'priority' => $record->priority,
            ])
            ->all();

        $results = $this->checker->check($domain->name, $nameservers, $expected);

        $checkedAt = now();

        $created = [];
        foreach ($results as $result) {
            $created[] = DnsPropagationCheck::create([
                'organization_id' => $zone->organization_id,
                'zone_id' => $zone->id,
                'nameserver' => $result['nameserver'],
                'record_type' => $result['record_type'],
                'record_name' => $result['record_name'],
                'expected' => $result['expected'],
                'observed' => $result['observed'],
                'status' => $result['status'],
                'checked_at' => $checkedAt,
            ]);
        }

        return $this->buildStatus($zone, $created);
    }

    /**
     * @param  array<int, DnsPropagationCheck>  $checks
     * @return array{domain: string, nameservers: array<int, string>, checked_at: ?string, data: array<int, DnsPropagationCheck>, summary: array{synced: int, pending: int, outdated: int, error: int, total: int}}
     */
    private function buildStatus(DnsZone $zone, array $checks): array
    {
        $summary = ['synced' => 0, 'pending' => 0, 'outdated' => 0, 'error' => 0, 'total' => count($checks)];

        foreach ($checks as $check) {
            if (isset($summary[$check->status])) {
                $summary[$check->status]++;
            }
        }

        return [
            'domain' => $zone->domain->name,
            'nameservers' => $zone->domain->nameservers ?: config('nexus.domain.default_nameservers', ['ns1.omnex.io', 'ns2.omnex.io']),
            'checked_at' => $checks !== [] ? $checks[0]->checked_at?->toIso8601String() : null,
            'data' => $checks,
            'summary' => $summary,
        ];
    }
}
