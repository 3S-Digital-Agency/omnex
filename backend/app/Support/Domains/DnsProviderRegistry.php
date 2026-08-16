<?php

namespace App\Support\Domains;

use App\Contracts\DnsProviderInterface;
use App\Support\Domains\Providers\SandboxDnsProvider;
use InvalidArgumentException;

final class DnsProviderRegistry
{
    /** @var array<string, DnsProviderInterface> */
    private array $providers = [];

    public function __construct()
    {
        $this->register(new SandboxDnsProvider);
    }

    public function register(DnsProviderInterface $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    public function get(?string $name = null): DnsProviderInterface
    {
        $name ??= config('nexus.domain.dns_provider', 'sandbox');

        return $this->providers[$name]
            ?? throw new InvalidArgumentException("Unknown DNS provider [{$name}].");
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->providers);
    }
}
