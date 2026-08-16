<?php

namespace App\Support\Billing\Providers;

use App\Contracts\PaymentProviderInterface;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Billing\PaymentWebhookEvent;
use Illuminate\Support\Str;

/**
 * Deterministic payment provider for local/test environments. Checkout
 * returns a hosted (frontend) URL and never touches the network. Webhooks
 * are unsigned JSON (the signature header is ignored) so tests and the demo
 * can simulate success/failure without credentials:
 *
 *   { "checkout_id": "...", "outcome": "succeeded"|"failed", "amount": 1000,
 *     "currency": "usd", "email": "..." }
 */
final class SandboxPaymentProvider implements PaymentProviderInterface
{
    public function name(): string
    {
        return 'sandbox';
    }

    public function label(): string
    {
        return 'Sandbox';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function createCheckout(Plan $plan, Subscription $subscription, ?Coupon $coupon = null): array
    {
        return [
            'url' => '/billing/sandbox/checkout/'.Str::uuid(),
            'checkout_id' => 'sandbox-cs-'.Str::lower(Str::random(16)),
        ];
    }

    public function verifyWebhook(string $payload, string $signature): PaymentWebhookEvent
    {
        $data = json_decode($payload, true);

        if (! is_array($data)) {
            return new PaymentWebhookEvent('payment.failed');
        }

        $outcome = ($data['outcome'] ?? 'succeeded') === 'failed' ? 'payment.failed' : 'payment.succeeded';

        return new PaymentWebhookEvent(
            type: $outcome,
            checkoutId: isset($data['checkout_id']) ? (string) $data['checkout_id'] : null,
            providerSubscriptionId: isset($data['provider_subscription_id']) ? (string) $data['provider_subscription_id'] : null,
            providerInvoiceId: isset($data['provider_invoice_id']) ? (string) $data['provider_invoice_id'] : null,
            amount: (int) ($data['amount'] ?? 0),
            currency: (string) ($data['currency'] ?? 'usd'),
            email: isset($data['email']) ? (string) $data['email'] : null,
            raw: $data,
        );
    }
}
