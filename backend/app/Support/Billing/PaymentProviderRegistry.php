<?php

namespace App\Support\Billing;

use App\Contracts\PaymentProviderInterface;
use App\Support\Billing\Providers\SandboxPaymentProvider;
use App\Support\Billing\Providers\StripePaymentProvider;
use InvalidArgumentException;

final class PaymentProviderRegistry
{
    /** @var array<string, PaymentProviderInterface> */
    private array $providers = [];

    public function __construct()
    {
        $this->register(new SandboxPaymentProvider);
        $this->register(new StripePaymentProvider);
    }

    public function register(PaymentProviderInterface $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    public function get(?string $name = null): PaymentProviderInterface
    {
        $name ??= config('omnex.billing.provider', 'sandbox');

        return $this->providers[$name]
            ?? throw new InvalidArgumentException("Unknown payment provider [{$name}].");
    }

    /**
     * @return array<int, array{name: string, label: string, configured: bool}>
     */
    public function all(): array
    {
        return array_map(
            fn (PaymentProviderInterface $provider) => [
                'name' => $provider->name(),
                'label' => $provider->label(),
                'configured' => $provider->isConfigured(),
            ],
            array_values($this->providers),
        );
    }
}
