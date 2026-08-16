<?php

namespace App\Console\Commands;

use App\Models\Coupon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Creates (or updates) the Stripe Coupon objects backing the OMNEX coupon
 * catalog and stores their ids on the `coupons` table so StripePaymentProvider
 * can attach them to Checkout Sessions. Requires STRIPE_SECRET_KEY.
 */
class SyncStripeCoupons extends Command
{
    protected $signature = 'omnex:stripe-sync-coupons {--update : Re-create coupons even when a coupon id is already stored}';

    protected $description = 'Create Stripe Coupons for the OMNEX coupon catalog and store their ids.';

    public function handle(): int
    {
        $secret = (string) config('omnex.billing.stripe.secret');

        if ($secret === '') {
            $this->error('STRIPE_SECRET_KEY is not set.');

            return self::FAILURE;
        }

        $coupons = Coupon::query()->orderBy('code')->get();
        $created = 0;
        $skipped = 0;

        foreach ($coupons as $coupon) {
            if ($coupon->stripe_coupon_id !== null && ! $this->option('update')) {
                $this->line("  {$coupon->code}: coupon {$coupon->stripe_coupon_id} (already set)");
                $skipped++;

                continue;
            }

            $payload = [
                'name' => $coupon->name,
                'metadata[coupon_code]' => $coupon->code,
            ];

            if ($coupon->discount_type === 'percent') {
                $payload['percent_off'] = $coupon->discount_value;
            } else {
                $payload['amount_off'] = $coupon->discount_value;
                $payload['currency'] = $coupon->currency;
            }

            if ($coupon->max_redemptions !== null) {
                $payload['max_redemptions'] = $coupon->max_redemptions;
            }

            if ($coupon->expires_at !== null) {
                $payload['redeem_by'] = $coupon->expires_at->getTimestamp();
            }

            $stripeCoupon = Http::withBasicAuth($secret, '')
                ->asForm()
                ->post('https://api.stripe.com/v1/coupons', $payload)
                ->throw()
                ->json();

            $coupon->update(['stripe_coupon_id' => (string) $stripeCoupon['id']]);

            $this->line("  {$coupon->code}: created coupon {$stripeCoupon['id']} ({$coupon->discount_type} {$coupon->discount_value})");
            $created++;
        }

        $this->info("Sync complete: {$created} created, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
