<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'slug' => Str::slug(fake()->unique()->words(2, true)),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'price_monthly' => fake()->numberBetween(0, 9999),
            'price_yearly' => fake()->numberBetween(0, 99999),
            'currency' => 'usd',
            'features' => ['Feature A', 'Feature B'],
            'stripe_price_id' => null,
            'active' => true,
            'sort' => 0,
        ];
    }
}
