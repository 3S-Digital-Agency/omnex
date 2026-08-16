<?php

namespace App\Support\Cloud\Providers;

/**
 * Deterministic synthetic resource samples for the real-time metrics stream.
 *
 * Values are derived from a stable seed (the provider server id) plus a
 * 5-second time bucket, so the UI and the test suite are reproducible while
 * still visibly moving over time. Real platforms (Hetzner/DigitalOcean)
 * expose time-series metrics APIs; until their aggregation is wired this is
 * the placeholder so the stream works identically for every provider.
 *
 * @return array{cpu: int, memory_used: int, memory_total: int, disk_used: int, disk_total: int}
 */
trait GeneratesSyntheticMetrics
{
    /**
     * @return array{cpu: int, memory_used: int, memory_total: int, disk_used: int, disk_total: int}
     */
    protected function sampleSyntheticMetrics(string $seed): array
    {
        $base = crc32($seed);
        $tick = intdiv(time(), 5);

        $cpu = (int) round(12 + abs(sin(($base % 41) / 41 * 2 * M_PI + $tick * 0.22)) * 68 + ($tick % 6));
        $cpu = max(5, min(95, $cpu));

        $memoryTotal = 4 * 1024 * 1024 * 1024; // 4 GiB
        $memoryUsed = (int) round($memoryTotal * min(0.92, 0.26 + 0.34 * abs(sin(($base % 53) / 53 * 2 * M_PI + $tick * 0.14))));

        $diskTotal = 80 * 1024 * 1024 * 1024; // 80 GiB
        $diskUsed = (int) round($diskTotal * min(0.96, 0.34 + 0.08 * abs(sin(($base % 31) / 31 * 2 * M_PI + $tick * 0.05))));

        return [
            'cpu' => $cpu,
            'memory_used' => $memoryUsed,
            'memory_total' => $memoryTotal,
            'disk_used' => $diskUsed,
            'disk_total' => $diskTotal,
        ];
    }
}
