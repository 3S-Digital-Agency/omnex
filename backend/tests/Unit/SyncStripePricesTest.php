<?php

use App\Models\Plan;
use Illuminate\Support\Facades\Http;

it('fails when no stripe secret is set', function () {
    config()->set('omnex.billing.stripe.secret', '');

    $this->artisan('omnex:stripe-sync-prices')
        ->expectsOutputToContain('STRIPE_SECRET_KEY is not set')
        ->assertExitCode(1);
});

it('creates stripe prices for plans without one', function () {
    config()->set('omnex.billing.stripe.secret', 'sk_test_x');

    $plan = Plan::factory()->create([
        'slug' => 'starter',
        'name' => 'Starter',
        'price_monthly' => 1200,
        'currency' => 'usd',
        'stripe_price_id' => null,
    ]);

    Http::fake([
        'api.stripe.com/v1/prices' => Http::response([
            'id' => 'price_123',
            'unit_amount' => 1200,
            'currency' => 'usd',
        ]),
    ]);

    $this->artisan('omnex:stripe-sync-prices')
        ->expectsOutputToContain('price_123')
        ->assertExitCode(0);

    expect($plan->refresh()->stripe_price_id)->toBe('price_123');
});

it('skips plans that already have a price id', function () {
    config()->set('omnex.billing.stripe.secret', 'sk_test_x');

    Plan::factory()->create([
        'slug' => 'pro',
        'name' => 'Pro',
        'price_monthly' => 4900,
        'stripe_price_id' => 'price_existing',
    ]);

    Http::fake(['api.stripe.com/v1/prices' => Http::response(['id' => 'price_new'])]);

    $this->artisan('omnex:stripe-sync-prices')
        ->expectsOutputToContain('already set')
        ->assertExitCode(0);

    Http::assertNotSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/prices');
});
