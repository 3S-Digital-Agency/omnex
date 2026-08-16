<?php

namespace Database\Factories;

use App\Models\SecurityFinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityFinding>
 */
class SecurityFindingFactory extends Factory
{
    protected $model = SecurityFinding::class;

    public function definition(): array
    {
        $rule = fake()->randomElement(['mfa', 'single_member', 'email', 'domain_expiring', 'dnssec_disabled']);

        return [
            'rule' => $rule,
            'dedupe_key' => $rule.':org',
            'severity' => fake()->randomElement(['high', 'medium', 'low']),
            'status' => 'open',
            'metadata' => [],
        ];
    }
}
