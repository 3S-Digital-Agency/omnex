<?php

namespace App\Support\Domains\Providers;

use App\Contracts\DnsPropagationCheckerInterface;

/**
 * Deterministic sandbox: the propagation state of a record on a nameserver is
 * derived from a stable hash of (domain, nameserver, record). No network is
 * touched, and repeated checks return identical results — tests stay
 * reproducible. A real implementation would query each nameserver directly.
 */
final class SandboxDnsPropagationChecker implements DnsPropagationCheckerInterface
{
    public function check(string $domain, array $nameservers, array $expectedRecords): array
    {
        $checks = [];
        $now = now()->toIso8601String();

        foreach ($nameservers as $nameserver) {
            foreach ($expectedRecords as $record) {
                $seed = crc32($domain.'|'.$nameserver.'|'.$record['name'].'|'.$record['type'].'|'.$record['content']);
                $roll = abs($seed) % 10;

                $status = match (true) {
                    $roll < 7 => 'synced',
                    $roll < 9 => 'pending',
                    default => 'outdated',
                };

                $checks[] = [
                    'nameserver' => $nameserver,
                    'record_type' => $record['type'],
                    'record_name' => $record['name'],
                    'status' => $status,
                    'expected' => [$record['content']],
                    'observed' => $status === 'synced' ? [$record['content']] : null,
                    'checked_at' => $now,
                ];
            }
        }

        return $checks;
    }
}
