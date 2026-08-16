<?php

namespace Database\Factories;

use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    protected $model = Domain::class;

    public function definition(): array
    {
        $tld = fake()->randomElement(['com', 'io', 'dev', 'net', 'org']);

        return [
            'name' => strtolower(fake()->unique()->domainWord()).'.'.$tld,
            'status' => 'active',
            'provider' => 'sandbox',
            'registered_at' => now()->subDays(fake()->numberBetween(10, 300)),
            'expires_at' => now()->addYear(),
            'auto_renew' => true,
            'privacy_protection' => true,
            'transfer_lock' => true,
        ];
    }
}
