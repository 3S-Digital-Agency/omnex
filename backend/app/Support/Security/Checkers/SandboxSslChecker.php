<?php

namespace App\Support\Security\Checkers;

use App\Contracts\SslCheckerInterface;

/**
 * Deterministic sandbox checker: no network. The "days remaining" is derived
 * from a stable hash of the target so the same target always yields the same
 * certificate state (testable), spread across a realistic 5–400 day range.
 * Sites not served over HTTPS are reported as invalid.
 */
final class SandboxSslChecker implements SslCheckerInterface
{
    public function __construct(private readonly int $warningDays = 30) {}

    public function check(string $targetType, string $targetId, string $targetName): array
    {
        $now = now();

        if ($targetType === 'site' && ! str_starts_with($targetName, 'https://')) {
            return [
                'status' => 'invalid',
                'days_remaining' => null,
                'checked_at' => $now->toIso8601String(),
                'details' => ['reason' => 'Not served over HTTPS', 'url' => $targetName],
            ];
        }

        $daysRemaining = $this->deterministicDays($targetId);

        return [
            'status' => $daysRemaining <= $this->warningDays ? 'expiring' : 'valid',
            'days_remaining' => $daysRemaining,
            'checked_at' => $now->toIso8601String(),
            'details' => ['target' => $targetName],
        ];
    }

    private function deterministicDays(string $targetId): int
    {
        $hash = crc32($targetId) & 0x7FFFFFFF;

        // Stable 5..400 range.
        return 5 + ($hash % 396);
    }
}
