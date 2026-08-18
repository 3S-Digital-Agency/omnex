<?php

use App\Support\Storage\Providers\R2StorageProvider;
use App\Support\Storage\Providers\S3StorageProvider;
use App\Support\Storage\Providers\SandboxStorageProvider;

it('stores, fetches and deletes objects in the sandbox', function () {
    $provider = new SandboxStorageProvider;

    expect($provider->name())->toBe('sandbox');
    expect($provider->label())->toBe('Sandbox');
    expect($provider->isConfigured())->toBeTrue();

    $result = $provider->put('org/f/v1', 'hello', 'text/plain');

    expect($result['size'])->toBe(5);
    expect($provider->exists('org/f/v1'))->toBeTrue();
    expect($provider->get('org/f/v1'))->toBe('hello');
    expect($provider->get('missing'))->toBeNull();

    $provider->delete('org/f/v1');

    expect($provider->exists('org/f/v1'))->toBeFalse();
});

it('mints signed urls in the sandbox', function () {
    $provider = new SandboxStorageProvider;

    $download = $provider->signedDownloadUrl('org/f/v1', 'file.txt');
    expect($download)->toContain('file.txt')->toContain('ttl=300');

    $upload = $provider->signedUploadUrl('org/f/v1', 'text/plain');
    expect($upload)->toContain('upload=1');
});

it('reports S3 configuration from credentials', function () {
    $provider = new S3StorageProvider;

    expect($provider->name())->toBe('s3');
    expect($provider->label())->toBe('S3');
    expect($provider->isConfigured())->toBeFalse();

    config()->set('omnex.storage.s3.endpoint', 'https://s3.example.com');
    config()->set('omnex.storage.s3.bucket', 'omnex');
    config()->set('omnex.storage.s3.key', 'AKIA');
    config()->set('omnex.storage.s3.secret', 'secret');

    expect($provider->isConfigured())->toBeTrue();
});

it('reports R2 configuration and derives the account endpoint', function () {
    $provider = new R2StorageProvider;

    expect($provider->name())->toBe('r2');
    expect($provider->label())->toBe('Cloudflare R2');
    expect($provider->isConfigured())->toBeFalse();

    config()->set('omnex.storage.r2.endpoint', '');
    config()->set('omnex.storage.r2.account_id', 'acct-r2-123');
    config()->set('omnex.storage.r2.bucket', 'omnex');
    config()->set('omnex.storage.r2.key', 'R2KEY');
    config()->set('omnex.storage.r2.secret', 'r2-secret');

    expect($provider->isConfigured())->toBeTrue();

    // The signed upload URL (no network I/O) must use the derived account
    // endpoint and the R2 region, and carry a SigV4 authorization query.
    $url = $provider->signedUploadUrl('org/f/v1', 'text/plain');

    expect($url)->toStartWith('https://acct-r2-123.r2.cloudflarestorage.com/omnex/org/f/v1?')
        ->toContain('X-Amz-Algorithm=AWS4-HMAC-SHA256')
        ->toContain(urlencode('auto/s3/aws4_request'));
});

it('honours an explicit R2 endpoint over the derived one', function () {
    config()->set('omnex.storage.r2.endpoint', 'https://custom.r2.dev');
    config()->set('omnex.storage.r2.bucket', 'omnex');
    config()->set('omnex.storage.r2.key', 'R2KEY');
    config()->set('omnex.storage.r2.secret', 'r2-secret');

    $url = (new R2StorageProvider)->signedUploadUrl('org/f/v1', 'text/plain');

    expect($url)->toStartWith('https://custom.r2.dev/omnex/org/f/v1?');
});
