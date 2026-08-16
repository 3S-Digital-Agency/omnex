<?php

namespace Database\Factories;

use App\Models\DnsZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DnsZone>
 */
class DnsZoneFactory extends Factory
{
    protected $model = DnsZone::class;

    public function definition(): array
    {
        return [
            'provider' => 'sandbox',
            'status' => 'active',
        ];
    }
}
