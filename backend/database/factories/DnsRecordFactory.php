<?php

namespace Database\Factories;

use App\Models\DnsRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DnsRecord>
 */
class DnsRecordFactory extends Factory
{
    protected $model = DnsRecord::class;

    public function definition(): array
    {
        return [
            'type' => 'A',
            'name' => '@',
            'content' => fake()->unique()->ipv4(),
            'ttl' => 3600,
            'priority' => null,
            'proxied' => false,
        ];
    }
}
