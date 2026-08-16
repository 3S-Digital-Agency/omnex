<?php

namespace Database\Factories;

use App\Models\DriveVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriveVersion>
 */
class DriveVersionFactory extends Factory
{
    protected $model = DriveVersion::class;

    public function definition(): array
    {
        return [
            'version' => 1,
            'storage_key' => 'org/factory/'.fake()->unique()->uuid().'/v1',
            'size' => fake()->numberBetween(1, 1_000_000),
            'checksum' => hash('sha256', fake()->uuid()),
        ];
    }
}
