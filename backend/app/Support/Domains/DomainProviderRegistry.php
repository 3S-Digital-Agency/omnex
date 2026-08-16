<?php

namespace App\Support\Domains;

use App\Contracts\DomainProviderInterface;
use App\Support\Domains\Providers\CustomDomainProvider;
use App\Support\Domains\Providers\NamecheapDomainProvider;
use App\Support\Domains\Providers\OvhDomainProvider;
use App\Support\Domains\Providers\SandboxDomainProvider;
use InvalidArgumentException;

final class DomainProviderRegistry
{
    /** @var array<string, DomainProviderInterface> */
    private array $providers = [];

    public function __construct()
    {
        $this->register(new SandboxDomainProvider);
        $this->register(new NamecheapDomainProvider);
        $this->register(new OvhDomainProvider);
        $this->register(new CustomDomainProvider);
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

    /**
     * @return array<int, array{name: string, label: string, configured: bool}>
     */
    public function all(): array
    {
        return array_map(
            fn (DomainProviderInterface $provider) => [
                'name' => $provider->name(),
                'label' => $provider->label(),
                'configured' => $provider->isConfigured(),
            ],
            array_values($this->providers),
        );
    }
}
