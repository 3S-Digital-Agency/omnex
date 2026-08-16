<?php

namespace App\Support\Storage;

use App\Contracts\StorageProviderInterface;
use App\Support\Storage\Providers\SandboxStorageProvider;
use App\Support\Storage\Providers\S3StorageProvider;
use InvalidArgumentException;

final class StorageProviderRegistry
{
    /** @var array<string, StorageProviderInterface> */
    private array $providers = [];

    public function __construct()
    {
        $this->register(new SandboxStorageProvider);
        $this->register(new S3StorageProvider);
    }

    public function register(StorageProviderInterface $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    public function get(?string $name = null): StorageProviderInterface
    {
        $name ??= config('nexus.storage.provider', 'sandbox');

        return $this->providers[$name]
            ?? throw new InvalidArgumentException("Unknown storage provider [{$name}].");
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->providers);
    }
}
