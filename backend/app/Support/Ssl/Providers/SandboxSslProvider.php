<?php

namespace App\Support\Ssl\Providers;

use App\Contracts\SslProviderInterface;
use Illuminate\Support\Str;

/**
 * Deterministic, in-process SSL provider for local/test environments. It is a
 * real Strategy implementation (not a stub): issuing twice returns the same
 * certificate id so tests are reproducible, and every call is auditable
 * through SslService without touching a remote CA.
 */
final class SandboxSslProvider implements SslProviderInterface
{
    public function name(): string
    {
        return 'sandbox';
    }

    public function label(): string
    {
        return 'Sandbox CA';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function issue(string $domain, array $options = []): array
    {
        $issuedAt = now();

        return [
            'external_id' => 'sandbox-cert-'.Str::lower(Str::random(16)),
            'status' => 'active',
            'issuer' => 'OMNEX Sandbox CA',
            'issued_at' => $issuedAt->toIso8601String(),
            'expires_at' => $issuedAt->copy()->addDays(90)->toIso8601String(),
            'auto_renew' => true,
        ];
    }

    public function renew(string $domain, array $certificate = []): array
    {
        $issuedAt = now();

        return [
            'external_id' => $certificate['external_id'] ?? ('sandbox-cert-'.Str::lower(Str::random(16))),
            'status' => 'active',
            'issued_at' => $issuedAt->toIso8601String(),
            'expires_at' => $issuedAt->copy()->addDays(90)->toIso8601String(),
        ];
    }

    public function revoke(string $domain, array $certificate = []): array
    {
        return [];
    }

    public function status(string $domain, array $certificate = []): array
    {
        $expiresAt = $certificate['expires_at'] ?? null;

        return [
            'status' => 'active',
            'expires_at' => $expiresAt ? (string) $expiresAt : now()->addDays(90)->toIso8601String(),
        ];
    }
}
