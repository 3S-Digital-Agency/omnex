<?php

namespace App\Support\Billing;

/**
 * Normalized webhook event. Providers translate their native event shapes
 * (Stripe `checkout.session.completed`, `invoice.payment_succeeded`, …) into
 * one of two outcomes so BillingService never knows about a specific gateway.
 */
final readonly class PaymentWebhookEvent
{
    /**
     * @param  'payment.succeeded'|'payment.failed'  $type
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $type,
        public ?string $checkoutId = null,
        public ?string $providerSubscriptionId = null,
        public ?string $providerInvoiceId = null,
        public int $amount = 0,
        public string $currency = 'usd',
        public ?string $email = null,
        public array $raw = [],
    ) {}
}
