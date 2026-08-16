<?php

namespace Database\Factories;

use App\Models\DriveFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriveFile>
 */
class DriveFileFactory extends Factory
{
    protected $model = DriveFile::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word().'.txt',
            'storage_key' => 'org/factory/'.fake()->unique()->uuid().'/v1',
            'mime_type' => 'text/plain',
            'size' => fake()->numberBetween(1, 1_000_000),
            'checksum' => hash('sha256', fake()->uuid()),
            'version' => 1,
            'status' => 'active',
        ];
    }
}
