<?php

use App\Support\Billing\PaymentProviderException;
use App\Support\Billing\Providers\SandboxPaymentProvider;
use App\Support\Billing\Providers\StripePaymentProvider;

function stripeSignature(string $secret, string $payload): string
{
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    return "t={$timestamp},v1={$signature}";
}

it('maps a sandbox webhook to a succeeded event', function () {
    $event = (new SandboxPaymentProvider)->verifyWebhook(json_encode([
        'checkout_id' => 'sandbox-cs-1',
        'outcome' => 'succeeded',
        'amount' => 1200,
        'currency' => 'usd',
        'email' => 'owner@example.com',
    ]), '');

    expect($event->type)->toBe('payment.succeeded')
        ->and($event->checkoutId)->toBe('sandbox-cs-1')
        ->and($event->amount)->toBe(1200)
        ->and($event->email)->toBe('owner@example.com');
});

it('maps a sandbox webhook to a failed event', function () {
    $event = (new SandboxPaymentProvider)->verifyWebhook(json_encode([
        'checkout_id' => 'sandbox-cs-1',
        'outcome' => 'failed',
    ]), '');

    expect($event->type)->toBe('payment.failed');
});

it('keeps stripe unconfigured until credentials are set', function () {
    expect(app(StripePaymentProvider::class)->isConfigured())->toBeFalse();

    config()->set('omnex.billing.stripe.secret', 'sk_test_x');
    config()->set('omnex.billing.stripe.webhook_secret', 'whsec_x');

    expect(app(StripePaymentProvider::class)->isConfigured())->toBeTrue();
});

it('verifies and normalizes a stripe checkout session', function () {
    config()->set('omnex.billing.stripe.webhook_secret', 'whsec_test');

    $payload = json_encode([
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_123',
            'subscription' => 'sub_123',
            'invoice' => 'in_123',
            'amount_total' => 1200,
            'currency' => 'usd',
            'customer_details' => ['email' => 'owner@example.com'],
        ]],
    ]);

    $signature = stripeSignature('whsec_test', $payload);

    $event = app(StripePaymentProvider::class)->verifyWebhook($payload, $signature);

    expect($event->type)->toBe('payment.succeeded')
        ->and($event->checkoutId)->toBe('cs_123')
        ->and($event->providerSubscriptionId)->toBe('sub_123')
        ->and($event->providerInvoiceId)->toBe('in_123')
        ->and($event->amount)->toBe(1200);
});

it('rejects an invalid stripe signature', function () {
    config()->set('omnex.billing.stripe.webhook_secret', 'whsec_test');

    $payload = json_encode(['type' => 'invoice.payment_succeeded', 'data' => ['object' => []]]);

    expect(fn () => app(StripePaymentProvider::class)->verifyWebhook($payload, 't=1,v1=deadbeef'))
        ->toThrow(PaymentProviderException::class, 'Invalid Stripe webhook signature');
});

it('normalizes an invoice payment failure', function () {
    config()->set('omnex.billing.stripe.webhook_secret', 'whsec_test');

    $payload = json_encode([
        'type' => 'invoice.payment_failed',
        'data' => ['object' => [
            'id' => 'in_456',
            'subscription' => 'sub_456',
            'amount_due' => 4900,
            'currency' => 'usd',
        ]],
    ]);

    $event = app(StripePaymentProvider::class)->verifyWebhook(
        $payload,
        stripeSignature('whsec_test', $payload),
    );

    expect($event->type)->toBe('payment.failed')
        ->and($event->providerInvoiceId)->toBe('in_456')
        ->and($event->amount)->toBe(4900);
});
