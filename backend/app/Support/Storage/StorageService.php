<?php

namespace App\Support\Storage;

use App\Contracts\StorageProviderInterface;
use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\DriveVersion;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owns the OMNEX Drive lifecycle: folders, files, versions, trash and quota.
 * OMNEX is the system of record for metadata; a StorageProviderInterface only
 * stores/retrieves opaque bytes under a tenant-scoped key and mints signed
 * URLs. Every mutation is audited; the tenant global scope keeps each
 * organization's tree isolated.
 */
final class StorageService
{
    public function __construct(private StorageProviderRegistry $providers) {}

    /**
     * @return array<int, array{name: string, label: string, configured: bool}>
     */
    public function providers(): array
    {
        return $this->providers->all();
    }

    private function provider(): StorageProviderInterface
    {
        $provider = $this->providers->get();

        if (! $provider->isConfigured()) {
            throw new StorageProviderException("The [{$provider->label()}] storage provider is not configured.");
        }

        return $provider;
    }

    /**
     * @return array{folder: ?DriveFolder, folders: array<int, DriveFolder>, files: array<int, DriveFile>, quota: array{used: int, limit: int}}
     */
    public function list(?DriveFolder $folder = null): array
    {
        return [
            'folder' => $folder,
            'folders' => $this->folders($folder),
            'files' => $this->files($folder),
            'quota' => $this->quota(),
        ];
    }

    /**
     * @return array<int, DriveFile>
     */
    public function trash(): array
    {
        return DriveFile::query()
            ->whereNotNull('trashed_at')
            ->orderByDesc('trashed_at')
            ->get()
            ->all();
    }

    public function createFolder(?string $parentId, string $name): DriveFolder
    {
        $name = $this->validateName($name);

        $parent = $parentId !== null ? DriveFolder::findOrFail($parentId) : null;

        $folder = DriveFolder::create([
            'parent_id' => $parent?->id,
            'name' => $name,
        ]);

        AuditLogger::record('drive.folder_created', 'drive_folder', $folder->id, null, ['name' => $name]);

        return $folder;
    }

    public function renameFolder(DriveFolder $folder, string $name): DriveFolder
    {
        $name = $this->validateName($name);

        $before = $folder->name;
        $folder->update(['name' => $name]);

        AuditLogger::record('drive.folder_renamed', 'drive_folder', $folder->id, ['name' => $before], ['name' => $name]);

        return $folder;
    }

    public function deleteFolder(DriveFolder $folder): void
    {
        if ($folder->children()->exists() || $folder->files()->exists()) {
            throw ValidationException::withMessages(['folder' => ['Only empty folders can be deleted.']]);
        }

        $before = ['name' => $folder->name];
        $folder->delete();

        AuditLogger::record('drive.folder_deleted', 'drive_folder', null, $before, null);
    }

    public function upload(?string $folderId, string $name, string $contents, string $mimeType = 'application/octet-stream'): DriveFile
    {
        $name = $this->validateName($name);
        $folder = $folderId !== null ? DriveFolder::findOrFail($folderId) : null;

        $this->assertQuota(strlen($contents));

        $organizationId = app(TenantContext::class)->id();
        $fileId = (string) Str::uuid();
        $key = $this->storageKey($organizationId, $fileId, 1);

        // Remote first: store the bytes, then persist metadata. Mirrors the
        // domain engine's provider-then-database ordering.
        $this->provider()->put($key, $contents, $mimeType);

        return DB::transaction(function () use ($fileId, $folder, $name, $contents, $mimeType, $key) {
            $file = DriveFile::create([
                'id' => $fileId,
                'folder_id' => $folder?->id,
                'name' => $name,
                'storage_key' => $key,
                'mime_type' => $mimeType,
                'size' => strlen($contents),
                'checksum' => hash('sha256', $contents),
                'version' => 1,
                'status' => 'active',
            ]);

            DriveVersion::create([
                'organization_id' => $file->organization_id,
                'file_id' => $file->id,
                'version' => 1,
                'storage_key' => $key,
                'size' => strlen($contents),
                'checksum' => hash('sha256', $contents),
            ]);

            AuditLogger::record('drive.file_uploaded', 'drive_file', $file->id, null, $this->snapshot($file));

            return $file;
        });
    }

    public function contents(DriveFile $file): string
    {
        $contents = $this->provider()->get($file->storage_key);

        if ($contents === null) {
            throw new StorageProviderException("Object [{$file->storage_key}] is missing from storage.");
        }

        return $contents;
    }

    public function downloadUrl(DriveFile $file): string
    {
        return $this->provider()->signedDownloadUrl(
            $file->storage_key,
            $file->name,
            (int) config('omnex.storage.signed_url_ttl', 300),
        );
    }

    public function updateFile(DriveFile $file, ?string $name = null, ?string $contents = null, ?string $mimeType = null): DriveFile
    {
        $before = $this->snapshot($file);

        if ($name !== null) {
            $file->update(['name' => $this->validateName($name)]);
        }

        if ($contents !== null) {
            $file = $this->addVersion($file, $contents, $mimeType ?: $file->mime_type);
        }

        AuditLogger::record('drive.file_updated', 'drive_file', $file->id, $before, $this->snapshot($file));

        return $file->fresh();
    }

    public function trashFile(DriveFile $file): DriveFile
    {
        if ($file->trashed_at !== null) {
            throw ValidationException::withMessages(['file' => ['The file is already in the trash.']]);
        }

        $file->update(['status' => 'trashed', 'trashed_at' => now()]);

        AuditLogger::record('drive.file_trashed', 'drive_file', $file->id);

        return $file;
    }

    public function restoreFile(DriveFile $file): DriveFile
    {
        if ($file->trashed_at === null) {
            throw ValidationException::withMessages(['file' => ['The file is not in the trash.']]);
        }

        $file->update(['status' => 'active', 'trashed_at' => null]);

        AuditLogger::record('drive.file_restored', 'drive_file', $file->id);

        return $file;
    }

    public function deleteFile(DriveFile $file): void
    {
        $before = $this->snapshot($file);

        foreach ($file->versions as $version) {
            $this->provider()->delete($version->storage_key);
        }

        $file->versions()->delete();
        $file->delete();

        AuditLogger::record('drive.file_deleted', 'drive_file', null, $before, null);
    }

    /**
     * @return array<int, DriveVersion>
     */
    public function versions(DriveFile $file): array
    {
        return $file->versions()->get()->all();
    }

    public function restoreVersion(DriveFile $file, DriveVersion $version): DriveFile
    {
        $contents = $this->provider()->get($version->storage_key);

        if ($contents === null) {
            throw new StorageProviderException("Version [{$version->id}] is missing from storage.");
        }

        return $this->addVersion($file, $contents, $file->mime_type);
    }

    /**
     * @return array{used: int, limit: int}
     */
    public function quota(): array
    {
        return [
            'used' => (int) DriveVersion::query()->sum('size'),
            'limit' => (int) config('omnex.storage.default_quota_bytes', 0),
        ];
    }

    /**
     * Daily cumulative usage over the last `days` days (newest last). Each
     * bucket is the total bytes stored up to that date, so the timeline
     * reflects real growth from the version history.
     */
    public function usageHistory(int $days = 30): array
    {
        $days = max(7, min(90, $days));
        $today = now()->startOfDay();

        $versions = DriveVersion::query()
            ->selectRaw('DATE(created_at) as day, SUM(size) as bytes')
            ->where('created_at', '>=', $today->copy()->subDays($days - 1))
            ->groupByRaw('DATE(created_at)')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $samples = [];
        $cumulative = (int) DriveVersion::query()
            ->where('created_at', '<', $today->copy()->subDays($days - 1))
            ->sum('size');

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = $today->copy()->subDays($offset);
            $cumulative += (int) ($versions->get($date->toDateString())->bytes ?? 0);
            $samples[] = [
                'date' => $date->toDateString(),
                'bytes' => $cumulative,
            ];
        }

        return $samples;
    }

    /**
     * Store new bytes as the next version of an existing file (remote first,
     * then metadata). Also used to "restore" an old version as a new one.
     */
    private function addVersion(DriveFile $file, string $contents, string $mimeType): DriveFile
    {
        $this->assertQuota(strlen($contents));

        $next = $file->version + 1;
        $key = $this->storageKey($file->organization_id, $file->id, $next);

        $this->provider()->put($key, $contents, $mimeType);

        return DB::transaction(function () use ($file, $contents, $mimeType, $next, $key) {
            DriveVersion::create([
                'organization_id' => $file->organization_id,
                'file_id' => $file->id,
                'version' => $next,
                'storage_key' => $key,
                'size' => strlen($contents),
                'checksum' => hash('sha256', $contents),
            ]);

            $file->update([
                'storage_key' => $key,
                'size' => strlen($contents),
                'checksum' => hash('sha256', $contents),
                'version' => $next,
                'mime_type' => $mimeType,
            ]);

            AuditLogger::record('drive.file_versioned', 'drive_file', $file->id, ['version' => $next - 1], ['version' => $next]);

            return $file;
        });
    }

    /**
     * @return array<int, DriveFolder>
     */
    private function folders(?DriveFolder $folder): array
    {
        return DriveFolder::query()
            ->where('parent_id', $folder?->id)
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * @return array<int, DriveFile>
     */
    private function files(?DriveFolder $folder): array
    {
        return DriveFile::query()
            ->where('folder_id', $folder?->id)
            ->whereNull('trashed_at')
            ->orderBy('name')
            ->get()
            ->all();
    }

    private function storageKey(string $organizationId, string $fileId, int $version): string
    {
        return "{$organizationId}/{$fileId}/v{$version}";
    }

    private function validateName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['The name is required.']]);
        }

        if (mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => ['The name must not exceed 255 characters.']]);
        }

        if (str_contains($name, '/')) {
            throw ValidationException::withMessages(['name' => ['The name must not contain a slash.']]);
        }

        return $name;
    }

    private function assertQuota(int $incomingBytes): void
    {
        $limit = (int) config('omnex.storage.default_quota_bytes', 0);

        if ($limit <= 0) {
            return;
        }

        $used = (int) DriveVersion::query()->sum('size');

        if ($used + $incomingBytes > $limit) {
            throw ValidationException::withMessages(['contents' => ['Storage quota exceeded.']]);
        }
    }

    /**
     * @return array{id: string, name: string, mime_type: string, size: int, version: int, status: string}
     */
    private function snapshot(DriveFile $file): array
    {
        return [
            'id' => $file->id,
            'name' => $file->name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'version' => $file->version,
            'status' => $file->status,
        ];
    }
}
