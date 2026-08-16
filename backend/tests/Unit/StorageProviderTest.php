<?php

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

    config()->set('nexus.storage.s3.endpoint', 'https://s3.example.com');
    config()->set('nexus.storage.s3.bucket', 'omnex');
    config()->set('nexus.storage.s3.key', 'AKIA');
    config()->set('nexus.storage.s3.secret', 'secret');

    expect($provider->isConfigured())->toBeTrue();
});
