<?php

namespace App\Contracts;

/**
 * Certificate monitoring abstraction. A checker inspects a managed target
 * (site or domain) and reports the certificate state so the security engine
 * can persist `ssl_checks` and derive findings — without coupling to any
 * specific certificate authority or resolver.
 */
interface SslCheckerInterface
{
    /**
     * @return array{status: string, days_remaining: ?int, checked_at: string, details: array<string, mixed>}
     */
    public function check(string $targetType, string $targetId, string $targetName): array;
}
