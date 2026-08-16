<?php

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrgCreditEntry;
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
function billingCreditContext(): array
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

/**
 * @param  array<string, mixed>  $overrides
 */
function activateSubscription(Organization $organization, string $slug = 'pro', string $suffix = '1', array $overrides = []): Subscription
{
    $subscription = Subscription::create(array_merge([
        'organization_id' => $organization->id,
        'plan_id' => Plan::where('slug', $slug)->firstOrFail()->id,
        'provider' => 'sandbox',
        'checkout_id' => "sandbox-cs-{$suffix}",
        'status' => 'pending',
    ], $overrides));

    test()->postJson('/api/v1/billing/webhooks/sandbox', [
        'checkout_id' => "sandbox-cs-{$suffix}",
        'outcome' => 'succeeded',
        'provider_subscription_id' => "sandbox-sub-{$suffix}",
        'provider_invoice_id' => "sandbox-inv-{$suffix}",
        'amount' => 4900,
        'currency' => 'usd',
    ])->assertOk();

    return $subscription->fresh();
}

it('applies a coupon to a subscription and records the discount on the invoice', function () {
    [$user, $organization] = billingCreditContext();

    $coupon = Coupon::factory()->create([
        'code' => 'LAUNCH25',
        'discount_type' => 'percent',
        'discount_value' => 25,
    ]);

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'pro', 'coupon' => 'launch25'])
        ->assertStatus(201)
        ->assertJsonPath('subscription.coupon.code', 'LAUNCH25');

    $subscription = Subscription::where('organization_id', $organization->id)->first();
    expect($subscription->coupon_id)->toBe($coupon->id);

    $this->postJson('/api/v1/billing/webhooks/sandbox', [
        'checkout_id' => $subscription->checkout_id,
        'outcome' => 'succeeded',
        'provider_subscription_id' => 'sandbox-sub-c1',
        'provider_invoice_id' => 'sandbox-inv-c1',
        'amount' => 3675,
    ])->assertOk();

    $invoice = Invoice::where('subscription_id', $subscription->id)->first();
    expect($invoice)->not->toBeNull()
        ->and($invoice->amount)->toBe(4900)
        ->and($invoice->discount)->toBe(1225)
        ->and($invoice->amount_due)->toBe(3675);

    $redemption = CouponRedemption::where('subscription_id', $subscription->id)->first();
    expect($redemption)->not->toBeNull()
        ->and($redemption->discount_amount)->toBe(1225);

    expect($coupon->fresh()->times_redeemed)->toBe(1);
});

it('rejects an invalid or expired coupon', function () {
    [$user, $organization] = billingCreditContext();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'pro', 'coupon' => 'NOPE'])
        ->assertStatus(422);

    Coupon::factory()->create([
        'code' => 'EXPIRED',
        'discount_type' => 'percent',
        'discount_value' => 50,
        'expires_at' => now()->subDay(),
    ]);

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'pro', 'coupon' => 'EXPIRED'])
        ->assertStatus(422);
});

it('validates a coupon code', function () {
    [$user, $organization] = billingCreditContext();

    Coupon::factory()->create([
        'code' => 'EARLY10',
        'discount_type' => 'amount',
        'discount_value' => 1000,
        'currency' => 'usd',
    ]);

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/billing/coupons/validate', ['code' => 'early10'])
        ->assertOk()
        ->assertJsonPath('data.code', 'EARLY10')
        ->assertJsonPath('data.discount_type', 'amount')
        ->assertJsonPath('data.discount_value', 1000);
});

it('adds credits and reports the balance', function () {
    [$user, $organization] = billingCreditContext();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/billing/credits', ['amount' => 5000, 'reason' => 'Compensation'])
        ->assertStatus(201);

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/billing/credits')
        ->assertOk()
        ->assertJsonPath('data.balance', 5000)
        ->assertJsonCount(1, 'data.entries');
});

it('applies available credits to an invoice and consumes the ledger', function () {
    [$user, $organization] = billingCreditContext();

    OrgCreditEntry::create([
        'organization_id' => $organization->id,
        'amount' => 2000,
        'currency' => 'usd',
        'reason' => 'Welcome credit',
    ]);

    $subscription = activateSubscription($organization, 'pro', 'cred1');

    $invoice = Invoice::where('subscription_id', $subscription->id)->first();
    expect($invoice)->not->toBeNull()
        ->and($invoice->amount)->toBe(4900)
        ->and($invoice->credit_applied)->toBe(2000)
        ->and($invoice->amount_due)->toBe(2900);

    expect(OrgCreditEntry::where('organization_id', $organization->id)->sum('amount'))->toBe(0);
});

it('switches plan with proration credit for the unused period', function () {
    [$user, $organization] = billingCreditContext();

    $subscription = activateSubscription($organization, 'pro', 'pr1');

    // Rewind the period so ~half remains: prorated credit ≈ half of pro.
    $subscription->update([
        'current_period_start' => now()->subDays(15),
        'current_period_end' => now()->addDays(15),
    ]);

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/billing/change-plan', ['plan' => 'business'])
        ->assertOk()
        ->assertJsonPath('plan.slug', 'business');

    expect($subscription->refresh()->plan_id)->toBe(Plan::where('slug', 'business')->firstOrFail()->id)
        ->and($organization->refresh()->plan_tier)->toBe('business');

    $credit = OrgCreditEntry::where('organization_id', $organization->id)
        ->where('reason', 'proration')
        ->first();

    expect($credit)->not->toBeNull()
        ->and($credit->amount)->toBe(2450)
        ->and($subscription->status)->toBe('active');
});

it('rejects changing plan without an active subscription', function () {
    [$user, $organization] = billingCreditContext();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/billing/change-plan', ['plan' => 'business'])
        ->assertStatus(422);
});

it('enforces manage permission for credits', function () {
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
        ->getJson('/api/v1/billing/credits')
        ->assertOk();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/billing/credits', ['amount' => 1000, 'reason' => 'Test'])
        ->assertStatus(403);
});
