<?php

namespace Database\Factories;

use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(Str::random(8)),
            'name' => fake()->words(2, true),
            'discount_type' => 'percent',
            'discount_value' => 10,
            'currency' => 'usd',
            'max_redemptions' => null,
            'times_redeemed' => 0,
            'active' => true,
            'expires_at' => null,
        ];
    }
}
