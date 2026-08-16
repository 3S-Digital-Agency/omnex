<?php

namespace Database\Factories;

use App\Models\SiteDeployment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteDeployment>
 */
class SiteDeploymentFactory extends Factory
{
    protected $model = SiteDeployment::class;

    public function definition(): array
    {
        return [
            'number' => 1,
            'commit_sha' => substr(hash('sha256', fake()->uuid()), 0, 12),
            'status' => 'live',
            'url' => 'https://example.omnex-sites.test',
            'logs' => '[omnex-sites] deploy succeeded @ abc123',
            'deployed_at' => now(),
        ];
    }
}
