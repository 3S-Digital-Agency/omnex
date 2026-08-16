<?php

namespace App\Support\Domains\Providers;

use App\Contracts\DnsProviderInterface;
use Illuminate\Support\Str;

/**
 * No-op DNS provider for local/test environments. Record changes are persisted
 * by the DnsService (OMNEX is the system of record); this provider only stands
 * in for the eventual remote sync (Cloudflare / OVH / Route 53).
 */
final class SandboxDnsProvider implements DnsProviderInterface
{
    public function name(): string
    {
        return 'sandbox';
    }

    public function createZone(string $domain): array
    {
        return ['external_id' => 'sandbox-zone-'.Str::lower(Str::random(12))];
    }

    public function deleteZone(string $domain): array
    {
        return [];
    }

    public function createRecord(string $domain, array $record): array
    {
        return ['external_id' => 'sandbox-rr-'.Str::lower(Str::random(12))];
    }

    public function updateRecord(string $domain, array $record): array
    {
        return [];
    }

    public function deleteRecord(string $domain, array $record): array
    {
        return [];
    }

    /**
     * Deterministic DNSSEC: the DS record is derived from the domain name, so
     * enabling twice (after a disable) yields the same record and tests stay
     * reproducible.
     */
    public function enableDnssec(string $domain): array
    {
        return [[
            'key_tag' => abs(crc32($domain)) % 65536,
            'algorithm' => 13, // ECDSAP256SHA256
            'digest_type' => 2, // SHA-256
            'digest' => strtoupper(hash('sha256', "omnex:dnssec:{$domain}")),
        ]];
    }

    public function disableDnssec(string $domain): array
    {
        return [];
    }
}
