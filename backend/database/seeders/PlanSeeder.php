<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'free',
                'name' => 'Free',
                'description' => 'For personal projects and evaluation.',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'currency' => 'usd',
                'features' => ['1 seat', '1 domain', '1 GB storage', 'Community support'],
                'sort' => 0,
            ],
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'description' => 'For small teams shipping their first product.',
                'price_monthly' => 1200,
                'price_yearly' => 12000,
                'currency' => 'usd',
                'features' => ['5 seats', '10 domains', '25 GB storage', 'Email support'],
                'sort' => 1,
            ],
            [
                'slug' => 'pro',
                'name' => 'Pro',
                'description' => 'For growing teams with production workloads.',
                'price_monthly' => 4900,
                'price_yearly' => 49000,
                'currency' => 'usd',
                'features' => ['Unlimited seats', 'Unlimited domains', '250 GB storage', 'Priority support'],
                'sort' => 2,
            ],
            [
                'slug' => 'business',
                'name' => 'Business',
                'description' => 'For organizations with compliance and scale needs.',
                'price_monthly' => 19900,
                'price_yearly' => 199000,
                'currency' => 'usd',
                'features' => ['Unlimited everything', 'SLA & compliance', 'Dedicated support', 'SSO & audit'],
                'sort' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::firstOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
