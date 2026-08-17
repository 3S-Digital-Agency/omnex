<?php

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Notification;
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
function billingRenewalContext(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role_id' => Role::where('key', 'owner')->firstOrFail()->id,
        'status' => 'active',
    ]);

    return [$user, $organization];
}

it('renews an overdue sandbox subscription and records the invoice', function () {
    [$user, $organization] = billingRenewalContext();

    OrgCreditEntry::create([
        'organization_id' => $organization->id,
        'amount' => 1000,
        'currency' => 'usd',
        'reason' => 'Welcome credit',
    ]);

    Subscription::create([
        'organization_id' => $organization->id,
        'plan_id' => Plan::where('slug', 'pro')->firstOrFail()->id,
        'provider' => 'sandbox',
        'status' => 'active',
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->subDay(),
    ]);

    $this->artisan('omnex:billing-renewals')
        ->expectsOutputToContain('Renewed 1 subscription(s)')
        ->assertExitCode(0);

    $subscription = Subscription::first();
    expect($subscription->current_period_end->isAfter(now()))->toBeTrue()
        ->and($subscription->current_period_start->isPast())->toBeTrue();

    $invoice = Invoice::first();
    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe('paid')
        ->and($invoice->amount)->toBe(4900)
        ->and($invoice->credit_applied)->toBe(1000)
        ->and($invoice->amount_due)->toBe(3900)
        ->and($invoice->provider_invoice_id)->toBeNull();

    // Credits were consumed and the owners were notified + audited.
    expect(OrgCreditEntry::where('organization_id', $organization->id)->sum('amount'))->toBe(0)
        ->and(Notification::where('user_id', $user->id)->count())->toBe(1)
        ->and(Notification::first()->title)->toBe('Subscription renewed');
});

it('applies a persistent coupon discount to renewals without re-redeeming it', function () {
    [$user, $organization] = billingRenewalContext();

    $coupon = Coupon::factory()->create([
        'code' => 'PERMANENT',
        'discount_type' => 'percent',
        'discount_value' => 20,
    ]);

    Subscription::create([
        'organization_id' => $organization->id,
        'plan_id' => Plan::where('slug', 'pro')->firstOrFail()->id,
        'provider' => 'sandbox',
        'coupon_id' => $coupon->id,
        'status' => 'active',
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->subDay(),
    ]);

    $this->artisan('omnex:billing-renewals')->assertExitCode(0);

    $invoice = Invoice::first();
    expect($invoice->discount)->toBe(980)
        ->and($invoice->amount_due)->toBe(3920);

    // The coupon was redeemed at activation; renewals must not double-count it.
    expect(CouponRedemption::where('coupon_id', $coupon->id)->count())->toBe(0)
        ->and($coupon->fresh()->times_redeemed)->toBe(0);
});

it('ignores subscriptions that are not yet due', function () {
    [$user, $organization] = billingRenewalContext();

    Subscription::create([
        'organization_id' => $organization->id,
        'plan_id' => Plan::where('slug', 'starter')->firstOrFail()->id,
        'provider' => 'sandbox',
        'status' => 'active',
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);

    $this->artisan('omnex:billing-renewals')
        ->expectsOutputToContain('No overdue subscriptions to renew')
        ->assertExitCode(0);

    expect(Invoice::count())->toBe(0);
});

it('skips Stripe-managed and non-active subscriptions', function () {
    [$user, $organization] = billingRenewalContext();

    Subscription::create([
        'organization_id' => $organization->id,
        'plan_id' => Plan::where('slug', 'pro')->firstOrFail()->id,
        'provider' => 'stripe',
        'provider_subscription_id' => 'sub_live_123',
        'status' => 'active',
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->subDay(),
    ]);

    Subscription::create([
        'organization_id' => $organization->id,
        'plan_id' => Plan::where('slug', 'starter')->firstOrFail()->id,
        'provider' => 'sandbox',
        'status' => 'canceled',
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->subDay(),
    ]);

    $this->artisan('omnex:billing-renewals')
        ->expectsOutputToContain('No overdue subscriptions to renew')
        ->assertExitCode(0);

    expect(Invoice::count())->toBe(0);
});

it('exposes a cost breakdown aggregated by service', function () {
    [$user, $organization] = billingRenewalContext();

    Sanctum::actingAs($user);

    $starter = Plan::where('slug', 'starter')->firstOrFail();
    $pro = Plan::where('slug', 'pro')->firstOrFail();

    $subA = Subscription::create([
        'organization_id' => $organization->id,
        'plan_id' => $starter->id,
        'provider' => 'sandbox',
        'status' => 'active',
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->subDay(),
    ]);

    $subB = Subscription::create([
        'organization_id' => $organization->id,
        'plan_id' => $pro->id,
        'provider' => 'sandbox',
        'status' => 'active',
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->subDay(),
    ]);

    $this->artisan('omnex:billing-renewals')->assertExitCode(0);

    $breakdown = $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/billing/cost-breakdown')
        ->assertOk()
        ->json();

    expect($breakdown['services'])->not->toBeEmpty();

    $services = collect($breakdown['services'])->keyBy('service');
    expect($services->has('Starter'))->toBeTrue()
        ->and($services->has('Pro'))->toBeTrue()
        ->and($services->sum('amount'))->toBe((int) $breakdown['total'])
        ->and($breakdown['currency'])->toBe('usd');

    expect(Subscription::find($subA->id))->not->toBeNull()
        ->and(Subscription::find($subB->id))->not->toBeNull();
});

it('supports a dry run that writes nothing', function () {
    [$user, $organization] = billingRenewalContext();

    Subscription::create([
        'organization_id' => $organization->id,
        'plan_id' => Plan::where('slug', 'pro')->firstOrFail()->id,
        'provider' => 'sandbox',
        'status' => 'active',
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->subDay(),
    ]);

    $this->artisan('omnex:billing-renewals', ['--dry-run' => true])
        ->expectsOutputToContain('would be renewed')
        ->assertExitCode(0);

    expect(Invoice::count())->toBe(0)
        ->and(Subscription::first()->current_period_end->isPast())->toBeTrue();
});
