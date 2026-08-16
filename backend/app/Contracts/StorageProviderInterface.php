<?php

namespace App\Contracts;

/**
 * Port for object storage (AWS S3, Cloudflare R2, MinIO, OVH Object Storage…).
 * OMNEX owns the file/folder metadata, versions and trash; a provider only
 * stores and retrieves opaque bytes under a tenant-scoped key and mints
 * signed URLs. The DriveService is the only writer to the `drive_*` tables.
 */
interface StorageProviderInterface
{
    public function name(): string;

    public function label(): string;

    /**
     * Whether the provider can actually talk to a real backend. Sandbox is
     * always configured; real providers activate only with credentials set.
     */
    public function isConfigured(): bool;

    /**
     * Store an object and return its remote identity + size.
     *
     * @return array{etag: ?string, size: int}
     */
    public function put(string $key, string $contents, string $mimeType = 'application/octet-stream'): array;

    /**
     * Fetch an object's bytes, or null when it does not exist.
     */
    public function get(string $key): ?string;

    public function delete(string $key): void;

    public function exists(string $key): bool;

    public function signedDownloadUrl(string $key, string $fileName, int $ttl = 300): string;

    public function signedUploadUrl(string $key, string $mimeType, int $ttl = 300): string;
}
