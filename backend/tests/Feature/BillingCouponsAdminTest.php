<?php

use App\Models\Coupon;
use App\Models\CouponRedemption;
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
function billingAdminContext(): array
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

it('lists coupons with their usage counts', function () {
    [$user, $organization] = billingAdminContext();

    Coupon::factory()->create(['code' => 'SUMMER20', 'discount_type' => 'percent', 'discount_value' => 20]);
    Coupon::factory()->create(['code' => 'GIFT10', 'discount_type' => 'amount', 'discount_value' => 1000, 'active' => false]);

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/billing/coupons')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.code', 'GIFT10')
        ->assertJsonPath('data.0.active', false)
        ->assertJsonPath('data.1.code', 'SUMMER20');
});

it('creates a percent coupon with an uppercased code', function () {
    [$user, $organization] = billingAdminContext();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/billing/coupons', [
            'code' => 'launch30',
            'name' => 'Launch 30%',
            'discount_type' => 'percent',
            'discount_value' => 30,
            'max_redemptions' => 100,
        ])
        ->assertStatus(201)
        ->assertJsonPath('code', 'LAUNCH30')
        ->assertJsonPath('discount_type', 'percent')
        ->assertJsonPath('discount_value', 30)
        ->assertJsonPath('active', true);
});

it('rejects an invalid percent and a duplicate code', function () {
    [$user, $organization] = billingAdminContext();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/billing/coupons', [
            'code' => 'BAD',
            'name' => 'Bad',
            'discount_type' => 'percent',
            'discount_value' => 150,
        ])
        ->assertStatus(422);

    Coupon::factory()->create(['code' => 'DUP']);

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/billing/coupons', [
            'code' => 'dup',
            'name' => 'Duplicate',
            'discount_type' => 'amount',
            'discount_value' => 500,
        ])
        ->assertStatus(422);
});

it('updates and toggles a coupon', function () {
    [$user, $organization] = billingAdminContext();

    $coupon = Coupon::factory()->create(['code' => 'OLDCODE', 'active' => true]);

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson("/api/v1/billing/coupons/{$coupon->id}", [
            'name' => 'Renamed',
            'active' => false,
            'max_redemptions' => 50,
        ])
        ->assertOk()
        ->assertJsonPath('name', 'Renamed')
        ->assertJsonPath('active', false)
        ->assertJsonPath('max_redemptions', 50);

    expect($coupon->fresh()->code)->toBe('OLDCODE');
});

it('lists redemptions with the organization name', function () {
    [$user, $organization] = billingAdminContext();

    $coupon = Coupon::factory()->create(['code' => 'USED25', 'discount_type' => 'percent', 'discount_value' => 25]);

    $subscription = Subscription::create([
        'organization_id' => $organization->id,
        'plan_id' => Plan::where('slug', 'pro')->firstOrFail()->id,
        'provider' => 'sandbox',
        'coupon_id' => $coupon->id,
        'checkout_id' => 'sandbox-cs-admin1',
        'status' => 'pending',
    ]);

    $this->postJson('/api/v1/billing/webhooks/sandbox', [
        'checkout_id' => 'sandbox-cs-admin1',
        'outcome' => 'succeeded',
        'provider_subscription_id' => 'sandbox-sub-admin1',
        'provider_invoice_id' => 'sandbox-inv-admin1',
        'amount' => 3675,
    ])->assertOk();

    expect(CouponRedemption::where('coupon_id', $coupon->id)->count())->toBe(1);

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/billing/coupons/{$coupon->id}/redemptions")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.organization_name', $organization->name)
        ->assertJsonPath('data.0.discount_amount', 1225);
});

it('enforces manage permission for coupon mutations', function () {
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
        ->getJson('/api/v1/billing/coupons')
        ->assertOk();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/billing/coupons', [
            'code' => 'NOPE',
            'name' => 'Nope',
            'discount_type' => 'percent',
            'discount_value' => 10,
        ])
        ->assertStatus(403);
});
