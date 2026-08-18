<?php

namespace App\Support\Storage\Providers;

/**
 * Cloudflare R2 as an explicit provider behind StorageProviderInterface.
 *
 * R2 is S3-compatible, so this reuses the SigV4 engine of S3StorageProvider
 * and only differs in configuration: an account-scoped endpoint
 * (https://{account_id}.r2.cloudflarestorage.com when not overridden), the
 * `auto` region, and credentials minted as R2 API tokens (Access Key ID +
 * Secret Access Key — the Cloudflare global API token is NOT valid for the
 * S3 API).
 *
 * Activates only when OMNEX_STORAGE_R2_* credentials are set; the sandbox
 * remains the default (OMNEX_STORAGE_PROVIDER=sandbox).
 */
final class R2StorageProvider extends S3StorageProvider
{
    public function name(): string
    {
        return 'r2';
    }

    public function label(): string
    {
        return 'Cloudflare R2';
    }

    protected function displayName(): string
    {
        return 'R2';
    }

    /**
     * @return array{endpoint: string, region: string, bucket: string, key: string, secret: string}
     */
    protected function config(): array
    {
        $accountId = (string) config('omnex.storage.r2.account_id');
        $endpoint = rtrim((string) config('omnex.storage.r2.endpoint'), '/');

        if ($endpoint === '' && $accountId !== '') {
            // Standard R2 account endpoint when only the account id is set.
            $endpoint = "https://{$accountId}.r2.cloudflarestorage.com";
        }

        return [
            'endpoint' => $endpoint,
            'region' => (string) config('omnex.storage.r2.region', 'auto'),
            'bucket' => (string) config('omnex.storage.r2.bucket'),
            'key' => (string) config('omnex.storage.r2.key'),
            'secret' => (string) config('omnex.storage.r2.secret'),
        ];
    }
}
