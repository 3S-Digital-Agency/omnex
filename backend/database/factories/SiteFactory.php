<?php

namespace Database\Factories;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'framework' => fake()->randomElement(['static', 'laravel', 'next']),
            'git_url' => 'https://github.com/example/'.Str::slug($name).'.git',
            'git_branch' => 'main',
            'provider' => 'sandbox',
            'provider_site_id' => 'sbox-site-'.Str::slug($name),
            'status' => 'ready',
            'url' => 'https://'.Str::slug($name).'.omnex-sites.test',
            'environment_variables' => ['APP_ENV' => 'production'],
        ];
    }
}
