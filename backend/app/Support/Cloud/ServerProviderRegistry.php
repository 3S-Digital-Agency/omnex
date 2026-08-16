<?php

namespace App\Support\Cloud;

use App\Contracts\ServerProviderInterface;
use App\Support\Cloud\Providers\CustomServerProvider;
use App\Support\Cloud\Providers\DigitalOceanServerProvider;
use App\Support\Cloud\Providers\HetznerServerProvider;
use App\Support\Cloud\Providers\SandboxServerProvider;
use InvalidArgumentException;

final class ServerProviderRegistry
{
    /** @var array<string, ServerProviderInterface> */
    private array $providers = [];

    public function __construct()
    {
        $this->register(new SandboxServerProvider);
        $this->register(new HetznerServerProvider);
        $this->register(new DigitalOceanServerProvider);
        $this->register(new CustomServerProvider);
    }

    public function register(ServerProviderInterface $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    public function get(?string $name = null): ServerProviderInterface
    {
        $name ??= config('omnex.cloud.provider', 'sandbox');

        return $this->providers[$name]
            ?? throw new InvalidArgumentException("Unknown cloud provider [{$name}].");
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
            fn (ServerProviderInterface $provider) => [
                'name' => $provider->name(),
                'label' => $provider->label(),
                'configured' => $provider->isConfigured(),
            ],
            array_values($this->providers),
        );
    }
}
