<?php

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PlanSeeder::class);
});

/**
 * @return array{0: User, 1: Organization}
 */
function billingContext(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role_id' => Role::where('key', 'owner')->firstOrFail()->id,
        'status' => 'active',
    ]);

    Sanctum::actingAs($user);

    return [$user, $organization];
}

it('lists the payment providers and plans', function () {
    [$user, $organization] = billingContext();

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/billing/providers')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'sandbox')
        ->assertJsonPath('data.0.configured', true)
        ->assertJsonPath('data.1.name', 'stripe')
        ->assertJsonPath('data.1.configured', false);

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/billing/plans')
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'free')
        ->assertJsonPath('data.1.slug', 'starter');
});

it('subscribes to a plan and returns a checkout url', function () {
    [$user, $organization] = billingContext();

    $response = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'starter'])
        ->assertStatus(201)
        ->assertJsonPath('subscription.status', 'pending')
        ->assertJsonPath('subscription.provider', 'sandbox')
        ->assertJsonStructure(['checkout_url']);

    expect($response->json('checkout_url'))->not->toBe('');

    expect(Subscription::where('organization_id', $organization->id)->count())->toBe(1);
});

it('rejects subscribing twice to the same plan', function () {
    [$user, $organization] = billingContext();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'starter'])
        ->assertStatus(201);

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'starter'])
        ->assertStatus(422);
});

it('activates the subscription and records a paid invoice on a successful webhook', function () {
    [$user, $organization] = billingContext();

    $checkout = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'pro'])
        ->assertStatus(201)
        ->json('subscription');

    // The sandbox webhook carries the checkout_id from the subscription.
    $subscription = Subscription::findOrFail($checkout['id']);

    $this->postJson('/api/v1/billing/webhooks/sandbox', [
        'checkout_id' => $subscription->checkout_id,
        'outcome' => 'succeeded',
        'provider_subscription_id' => 'sandbox-sub-1',
        'provider_invoice_id' => 'sandbox-inv-1',
        'amount' => 4900,
        'currency' => 'usd',
    ])->assertOk()->assertJsonPath('received', true);

    $subscription->refresh();

    expect($subscription->status)->toBe('active')
        ->and($subscription->provider_subscription_id)->toBe('sandbox-sub-1')
        ->and($subscription->organization->plan_tier)->toBe('pro');

    $invoice = Invoice::where('subscription_id', $subscription->id)->first();
    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe('paid')
        ->and($invoice->amount)->toBe(4900)
        ->and($invoice->provider_invoice_id)->toBe('sandbox-inv-1');

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/billing/subscription')
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
});

it('marks the subscription past_due on a failed webhook', function () {
    [$user, $organization] = billingContext();

    $subscription = Subscription::create([
        'organization_id' => $organization->id,
        'plan_id' => Plan::where('slug', 'starter')->firstOrFail()->id,
        'provider' => 'sandbox',
        'checkout_id' => 'sandbox-cs-fail',
        'status' => 'pending',
    ]);

    $this->postJson('/api/v1/billing/webhooks/sandbox', [
        'checkout_id' => 'sandbox-cs-fail',
        'outcome' => 'failed',
        'provider_invoice_id' => 'sandbox-inv-fail',
        'amount' => 1200,
    ])->assertOk();

    $subscription->refresh();

    expect($subscription->status)->toBe('past_due');

    $invoice = Invoice::where('subscription_id', $subscription->id)->first();
    expect($invoice->status)->toBe('failed');
});

it('is idempotent when a webhook is redelivered', function () {
    [$user, $organization] = billingContext();

    $subscription = Subscription::create([
        'organization_id' => $organization->id,
        'plan_id' => Plan::where('slug', 'starter')->firstOrFail()->id,
        'provider' => 'sandbox',
        'checkout_id' => 'sandbox-cs-idem',
        'status' => 'pending',
    ]);

    $payload = [
        'checkout_id' => 'sandbox-cs-idem',
        'outcome' => 'succeeded',
        'provider_subscription_id' => 'sandbox-sub-idem',
        'provider_invoice_id' => 'sandbox-inv-idem',
        'amount' => 1200,
    ];

    $this->postJson('/api/v1/billing/webhooks/sandbox', $payload)->assertOk();
    $this->postJson('/api/v1/billing/webhooks/sandbox', $payload)->assertOk();

    expect(Invoice::where('provider_invoice_id', 'sandbox-inv-idem')->count())->toBe(1);
});

it('cancels the subscription', function () {
    [$user, $organization] = billingContext();

    $subscription = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'pro'])
        ->assertStatus(201)
        ->json('subscription');

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/billing/subscriptions/{$subscription['id']}/cancel")
        ->assertOk()
        ->assertJsonPath('status', 'canceled');
});

it('enforces billing permissions', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role_id' => Role::where('key', 'viewer')->firstOrFail()->id,
        'status' => 'active',
    ]);

    Sanctum::actingAs($user);

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/billing/plans')
        ->assertOk();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'starter'])
        ->assertStatus(403);
});

it('isolates invoices between tenants', function () {
    [$userA, $orgA] = billingContext();

    $subscriptionA = Subscription::create([
        'organization_id' => $orgA->id,
        'plan_id' => Plan::where('slug', 'starter')->firstOrFail()->id,
        'provider' => 'sandbox',
        'checkout_id' => 'sandbox-cs-a',
        'status' => 'pending',
    ]);

    $this->postJson('/api/v1/billing/webhooks/sandbox', [
        'checkout_id' => 'sandbox-cs-a',
        'outcome' => 'succeeded',
        'provider_invoice_id' => 'sandbox-inv-a',
        'amount' => 1200,
    ])->assertOk();

    $userB = User::factory()->create();
    $orgB = Organization::factory()->create();
    Membership::create([
        'organization_id' => $orgB->id,
        'user_id' => $userB->id,
        'role_id' => Role::where('key', 'owner')->firstOrFail()->id,
        'status' => 'active',
    ]);

    Sanctum::actingAs($userB);

    $this->withHeader('X-Organization', $orgB->id)
        ->getJson('/api/v1/billing/invoices')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    expect($subscriptionA->refresh()->status)->toBe('active');
});
