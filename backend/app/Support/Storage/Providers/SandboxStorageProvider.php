<?php

namespace App\Support\Storage\Providers;

use App\Contracts\StorageProviderInterface;

/**
 * Deterministic in-memory object store for local/test environments. Content
 * lives only for the duration of the process — no bytes ever leave the box,
 * and signed URLs are clearly fake. Reproducible, never random.
 */
final class SandboxStorageProvider implements StorageProviderInterface
{
    /** @var array<string, string> */
    private static array $objects = [];

    public function name(): string
    {
        return 'sandbox';
    }

    public function label(): string
    {
        return 'Sandbox';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function put(string $key, string $contents, string $mimeType = 'application/octet-stream'): array
    {
        self::$objects[$key] = $contents;

        return [
            'etag' => hash('sha256', $contents),
            'size' => strlen($contents),
        ];
    }

    public function get(string $key): ?string
    {
        return self::$objects[$key] ?? null;
    }

    public function delete(string $key): void
    {
        unset(self::$objects[$key]);
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, self::$objects);
    }

    public function signedDownloadUrl(string $key, string $fileName, int $ttl = 300): string
    {
        return 'https://storage.sandbox.omnex.test/'.rawurlencode($key).'?dl='.rawurlencode($fileName).'&ttl='.$ttl;
    }

    public function signedUploadUrl(string $key, string $mimeType, int $ttl = 300): string
    {
        return 'https://storage.sandbox.omnex.test/'.rawurlencode($key).'?upload=1&ttl='.$ttl;
    }
}
