<?php

namespace App\Contracts;

use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Billing\PaymentProviderException;
use App\Support\Billing\PaymentWebhookEvent;

/**
 * Port for payment providers (Stripe first, then any gateway). OMNEX owns the
 * subscription + invoice model and is the system of record; a provider only
 * starts hosted checkout sessions and forwards webhook events.
 *
 * Implementations must never write to the OMNEX database — BillingService is
 * the only writer to `subscriptions` and `invoices`.
 */
interface PaymentProviderInterface
{
    public function name(): string;

    public function label(): string;

    /** Whether the provider has the credentials required to run. */
    public function isConfigured(): bool;

    /**
     * Start a hosted checkout session for the given plan + subscription.
     * When a coupon is applied, the provider must discount the hosted
     * checkout accordingly (Stripe: `discounts[0][coupon]`).
     *
     * @return array{url: string, checkout_id: string}
     */
    public function createCheckout(Plan $plan, Subscription $subscription, ?Coupon $coupon = null): array;

    /**
     * Verify and normalize an incoming webhook. `$payload` is the raw request
     * body (a provider signature must be computed over the exact bytes, not a
     * re-encoded JSON structure); `$signature` is the provider's signature
     * header value.
     *
     * @throws PaymentProviderException when the signature is invalid
     */
    public function verifyWebhook(string $payload, string $signature): PaymentWebhookEvent;
}
