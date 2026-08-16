<?php

namespace App\Console\Commands;

use App\Models\Plan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Creates (or updates) the recurring Stripe Prices backing each active plan
 * and stores their ids on the `plans` table so the StripePaymentProvider can
 * start Checkout Sessions. Requires STRIPE_SECRET_KEY.
 */
class SyncStripePrices extends Command
{
    protected $signature = 'omnex:stripe-sync-prices {--update : Re-create prices even when a price id is already stored}';

    protected $description = 'Create Stripe Prices for the OMNEX plan catalog and store their ids.';

    public function handle(): int
    {
        $secret = (string) config('omnex.billing.stripe.secret');

        if ($secret === '') {
            $this->error('STRIPE_SECRET_KEY is not set.');

            return self::FAILURE;
        }

        $plans = Plan::query()->where('active', true)->orderBy('sort')->get();
        $created = 0;
        $skipped = 0;

        foreach ($plans as $plan) {
            if ($plan->stripe_price_id !== null && ! $this->option('update')) {
                $this->line("  {$plan->slug}: price {$plan->stripe_price_id} (already set)");
                $skipped++;

                continue;
            }

            $price = Http::withBasicAuth($secret, '')
                ->asForm()
                ->post('https://api.stripe.com/v1/prices', [
                    'unit_amount' => $plan->price_monthly,
                    'currency' => $plan->currency,
                    'recurring[interval]' => 'month',
                    'product_data[name]' => $plan->name,
                    'metadata[plan_slug]' => $plan->slug,
                ])
                ->throw()
                ->json();

            $plan->update(['stripe_price_id' => (string) $price['id']]);

            $this->line("  {$plan->slug}: created price {$price['id']} ({$plan->price_monthly} {$plan->currency}/month)");
            $created++;
        }

        $this->info("Sync complete: {$created} created, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
