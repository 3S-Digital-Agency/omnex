<?php

namespace App\Support\Billing\Providers;

use App\Contracts\PaymentProviderInterface;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Billing\PaymentProviderException;
use App\Support\Billing\PaymentWebhookEvent;
use Illuminate\Support\Facades\Http;

/**
 * Stripe provider — hosted Checkout Sessions + webhook verification. Uses the
 * raw HTTP facade (no SDK dependency) like the other provider adapters. The
 * webhook signature is verified with the standard
 * `t=<timestamp>,v1=<HMAC-SHA256 of "timestamp.rawbody">` scheme using the
 * endpoint secret (STRIPE_WEBHOOK_SECRET).
 */
final class StripePaymentProvider implements PaymentProviderInterface
{
    public function name(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return 'Stripe';
    }

    public function isConfigured(): bool
    {
        return filled(config('omnex.billing.stripe.secret'))
            && filled(config('omnex.billing.stripe.webhook_secret'));
    }

    public function createCheckout(Plan $plan, Subscription $subscription, ?Coupon $coupon = null): array
    {
        $secret = (string) config('omnex.billing.stripe.secret');

        if ($plan->stripe_price_id === null) {
            throw new PaymentProviderException("Plan [{$plan->slug}] has no Stripe price id.");
        }

        $frontend = rtrim((string) config('omnex.billing.frontend_url', config('app.url')), '/');

        $payload = [
            'mode' => 'subscription',
            'line_items[0][price]' => $plan->stripe_price_id,
            'line_items[0][quantity]' => 1,
            'client_reference_id' => $subscription->id,
            'success_url' => $frontend.'/billing?checkout=success',
            'cancel_url' => $frontend.'/billing?checkout=canceled',
        ];

        // A coupon without a Stripe coupon id can only be discounted in the
        // sandbox; the Stripe checkout must reference a real coupon object.
        if ($coupon !== null && $coupon->stripe_coupon_id !== null) {
            $payload['discounts[0][coupon]'] = $coupon->stripe_coupon_id;
        }

        $session = Http::withBasicAuth($secret, '')
            ->asForm()
            ->post('https://api.stripe.com/v1/checkout/sessions', $payload)
            ->throw()
            ->json();

        return [
            'url' => (string) $session['url'],
            'checkout_id' => (string) $session['id'],
        ];
    }

    public function verifyWebhook(string $payload, string $signature): PaymentWebhookEvent
    {
        $secret = (string) config('omnex.billing.stripe.webhook_secret');

        $timestamp = null;
        $provided = null;

        foreach (explode(',', $signature) as $part) {
            if (str_starts_with($part, 't=')) {
                $timestamp = substr($part, 2);
            } elseif (str_starts_with($part, 'v1=')) {
                $provided = substr($part, 3);
            }
        }

        if ($timestamp === null || $provided === null) {
            throw new PaymentProviderException('Invalid Stripe signature header.');
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        if (! hash_equals($expected, $provided)) {
            throw new PaymentProviderException('Invalid Stripe webhook signature.');
        }

        $event = json_decode($payload, true);

        if (! is_array($event)) {
            throw new PaymentProviderException('Invalid Stripe webhook payload.');
        }

        return $this->normalize((string) ($event['type'] ?? ''), (array) ($event['data']['object'] ?? []), $event);
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $raw
     */
    private function normalize(string $type, array $object, array $raw): PaymentWebhookEvent
    {
        $providerSubscriptionId = isset($object['subscription']) ? (string) $object['subscription'] : null;
        $providerInvoiceId = isset($object['invoice']) ? (string) $object['invoice'] : null;

        return match ($type) {
            'checkout.session.completed' => new PaymentWebhookEvent(
                type: 'payment.succeeded',
                checkoutId: isset($object['id']) ? (string) $object['id'] : null,
                providerSubscriptionId: $providerSubscriptionId,
                providerInvoiceId: $providerInvoiceId,
                amount: (int) ($object['amount_total'] ?? 0),
                currency: (string) ($object['currency'] ?? 'usd'),
                email: isset($object['customer_details']['email']) ? (string) $object['customer_details']['email'] : null,
                raw: $raw,
            ),
            'invoice.payment_succeeded' => new PaymentWebhookEvent(
                type: 'payment.succeeded',
                providerSubscriptionId: $providerSubscriptionId,
                providerInvoiceId: isset($object['id']) ? (string) $object['id'] : null,
                amount: (int) ($object['amount_paid'] ?? 0),
                currency: (string) ($object['currency'] ?? 'usd'),
                email: isset($object['customer_email']) ? (string) $object['customer_email'] : null,
                raw: $raw,
            ),
            'invoice.payment_failed' => new PaymentWebhookEvent(
                type: 'payment.failed',
                providerSubscriptionId: $providerSubscriptionId,
                providerInvoiceId: isset($object['id']) ? (string) $object['id'] : null,
                amount: (int) ($object['amount_due'] ?? 0),
                currency: (string) ($object['currency'] ?? 'usd'),
                email: isset($object['customer_email']) ? (string) $object['customer_email'] : null,
                raw: $raw,
            ),
            default => new PaymentWebhookEvent('payment.failed', raw: $raw),
        };
    }
}
