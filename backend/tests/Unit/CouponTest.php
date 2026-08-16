<?php

use App\Models\Coupon;

it('computes a percent discount', function () {
    $coupon = Coupon::factory()->create([
        'discount_type' => 'percent',
        'discount_value' => 25,
    ]);

    expect($coupon->discountFor(4000))->toBe(1000);
});

it('computes an amount discount capped at the price', function () {
    $coupon = Coupon::factory()->create([
        'discount_type' => 'amount',
        'discount_value' => 1500,
        'currency' => 'usd',
    ]);

    expect($coupon->discountFor(4000))->toBe(1500)
        ->and($coupon->discountFor(500))->toBe(500);
});

it('returns zero discount when the coupon is invalid', function () {
    $expired = Coupon::factory()->create([
        'discount_type' => 'percent',
        'discount_value' => 50,
        'expires_at' => now()->subDay(),
    ]);

    $inactive = Coupon::factory()->create([
        'discount_type' => 'percent',
        'discount_value' => 50,
        'active' => false,
    ]);

    $used = Coupon::factory()->create([
        'discount_type' => 'percent',
        'discount_value' => 50,
        'max_redemptions' => 3,
        'times_redeemed' => 3,
    ]);

    expect($expired->isValid())->toBeFalse()
        ->and($expired->discountFor(4000))->toBe(0)
        ->and($inactive->isValid())->toBeFalse()
        ->and($inactive->discountFor(4000))->toBe(0)
        ->and($used->isValid())->toBeFalse()
        ->and($used->discountFor(4000))->toBe(0);
});

it('is valid when within limits', function () {
    $coupon = Coupon::factory()->create([
        'discount_type' => 'amount',
        'discount_value' => 1000,
        'max_redemptions' => 10,
        'times_redeemed' => 2,
        'expires_at' => now()->addMonth(),
    ]);

    expect($coupon->isValid())->toBeTrue();
});
