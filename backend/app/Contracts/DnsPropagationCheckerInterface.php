<?php

namespace App\Contracts;

/**
 * Port for per-nameserver DNS resolution checks. A real implementation
 * queries each authoritative nameserver directly (raw UDP/TCP DNS); the
 * sandbox returns deterministic simulated results so the feature is fully
 * testable without network access.
 */
interface DnsPropagationCheckerInterface
{
    /**
     * Resolve each expected record against every nameserver.
     *
     * @param  array<int, string>  $nameservers
     * @param  array<int, array{type: string, name: string, content: string, ttl?: int, priority?: ?int}>  $expectedRecords
     * @return array<int, array{nameserver: string, record_type: string, record_name: string, status: string, expected: ?array<int, string>, observed: ?array<int, string>, checked_at: string}>
     */
    public function check(string $domain, array $nameservers, array $expectedRecords): array;
}
