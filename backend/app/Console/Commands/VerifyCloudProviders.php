<?php

namespace App\Console\Commands;

use App\Support\Cloud\ServerProviderRegistry;
use Illuminate\Console\Command;

class VerifyCloudProviders extends Command
{
    protected $signature = 'omnex:cloud:verify-providers {provider? : Only verify this provider (sandbox|hetzner|digitalocean|custom)}';

    protected $description = 'Verify cloud provider credentials against their platforms (read-only, no provisioning, no cost)';

    public function handle(ServerProviderRegistry $registry): int
    {
        $only = $this->argument('provider');

        $providers = collect($registry->all())
            ->filter(fn (array $provider) => $only === null || $provider['name'] === $only)
            ->values();

        if ($only !== null && $providers->isEmpty()) {
            $this->error("Unknown provider [{$only}]. Valid: ".implode(', ', $registry->names()));

            return self::FAILURE;
        }

        $results = [];

        foreach ($providers as $info) {
            $provider = $registry->get($info['name']);
            $results[] = [$info['label'], $provider->verify()];
        }

        $this->table(
            ['Provider', 'Status', 'Detail'],
            array_map(
                fn (array $result) => [
                    $result[0],
                    $result[1]['ok'] ? '✔ valid' : '✘ failed',
                    $result[1]['detail'] ?? '',
                ],
                $results,
            ),
        );

        $failed = collect($results)->contains(fn (array $result) => ! $result[1]['ok']);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
