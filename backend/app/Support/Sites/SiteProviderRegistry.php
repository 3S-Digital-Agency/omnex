<?php

namespace App\Support\Sites;

use App\Contracts\SiteProviderInterface;
use App\Support\Sites\Providers\CustomSiteProvider;
use App\Support\Sites\Providers\SandboxSiteProvider;
use InvalidArgumentException;

final class SiteProviderRegistry
{
    /** @var array<string, SiteProviderInterface> */
    private array $providers = [];

    public function __construct()
    {
        $this->register(new SandboxSiteProvider);
        $this->register(new CustomSiteProvider);
    }

    public function register(SiteProviderInterface $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    public function get(?string $name = null): SiteProviderInterface
    {
        $name ??= config('omnex.sites.provider', 'sandbox');

        return $this->providers[$name]
            ?? throw new InvalidArgumentException("Unknown sites provider [{$name}].");
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
            fn (SiteProviderInterface $provider) => [
                'name' => $provider->name(),
                'label' => $provider->label(),
                'configured' => $provider->isConfigured(),
            ],
            array_values($this->providers),
        );
    }
}
