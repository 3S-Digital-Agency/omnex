<?php

namespace App\Support\Domains;

use App\Contracts\DomainProviderInterface;
use App\Support\Domains\Providers\SandboxDomainProvider;
use InvalidArgumentException;

final class DomainProviderRegistry
{
    /** @var array<string, DomainProviderInterface> */
    private array $providers = [];

    public function __construct()
    {
        $this->register(new SandboxDomainProvider);
    }

    public function register(DomainProviderInterface $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    public function get(?string $name = null): DomainProviderInterface
    {
        $name ??= config('nexus.domain.provider', 'sandbox');

        return $this->providers[$name]
            ?? throw new InvalidArgumentException("Unknown domain provider [{$name}].");
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->providers);
    }
}
