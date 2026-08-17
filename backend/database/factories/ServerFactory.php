<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    protected $model = Server::class;

    public function definition(): array
    {
        return [
            'name' => Str::title(fake()->unique()->words(2, true)),
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
            'provider' => 'sandbox',
            'provider_server_id' => (string) fake()->unique()->numberBetween(1000, 99999),
            'status' => 'running',
            'ipv4' => fake()->ipv4(),
            'ipv6' => null,
            'tags' => [],
            'snapshot_frequency' => 'disabled',
            'snapshot_retention_days' => 7,
            'last_snapshot_at' => null,
        ];
    }
}
