<?php

namespace Database\Factories;

use App\Models\DriveFolder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriveFolder>
 */
class DriveFolderFactory extends Factory
{
    protected $model = DriveFolder::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
        ];
    }
}
