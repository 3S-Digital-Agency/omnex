<?php

namespace App\Support\Ssl;

use App\Contracts\SslProviderInterface;
use App\Support\Ssl\Providers\CloudflareSslProvider;
use App\Support\Ssl\Providers\LetsEncryptSslProvider;
use App\Support\Ssl\Providers\SandboxSslProvider;
use InvalidArgumentException;

final class SslProviderRegistry
{
    /** @var array<string, SslProviderInterface> */
    private array $providers = [];

    public function __construct()
    {
        $this->register(new SandboxSslProvider);
        $this->register(new CloudflareSslProvider);
        $this->register(new LetsEncryptSslProvider);
    }

    public function register(SslProviderInterface $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    public function get(?string $name = null): SslProviderInterface
    {
        $name ??= config('omnex.ssl.provider', 'sandbox');

        return $this->providers[$name]
            ?? throw new InvalidArgumentException("Unknown SSL provider [{$name}].");
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
            fn (SslProviderInterface $provider) => [
                'name' => $provider->name(),
                'label' => $provider->label(),
                'configured' => $provider->isConfigured(),
            ],
            array_values($this->providers),
        );
    }
}
