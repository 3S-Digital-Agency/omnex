<?php

namespace App\Console\Commands;

use App\Support\Billing\BillingService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rolls overdue sandbox subscriptions into their next billing period and
 * records the renewal invoices. Scheduled daily (routes/console.php).
 *
 * Stripe-managed subscriptions (provider_subscription_id set) are skipped:
 * their renewals flow through Stripe webhooks.
 */
class ProcessBillingRenewals extends Command
{
    protected $signature = 'omnex:billing-renewals {--dry-run : Report what would renew without writing anything}';

    protected $description = 'Renew overdue subscriptions and record renewal invoices.';

    public function handle(BillingService $billing): int
    {
        $overdue = $billing->overdueSubscriptions();

        if ($overdue->isEmpty()) {
            $this->info('No overdue subscriptions to renew.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $renewed = 0;

        foreach ($overdue as $subscription) {
            if ($dryRun) {
                $this->line("  [dry-run] {$subscription->organization->name} — {$subscription->plan->slug} (period ended {$subscription->current_period_end?->toDateString()})");

                continue;
            }

            app(TenantContext::class)->set($subscription->organization_id, $subscription->organization);

            DB::transaction(function () use ($billing, $subscription) {
                $billing->renewSubscription($subscription);
            });

            $this->line("  renewed {$subscription->organization->name} — {$subscription->plan->slug} (next period ends {$subscription->fresh()->current_period_end?->toDateString()})");
            $renewed++;
        }

        if ($dryRun) {
            $this->info("Dry run: {$overdue->count()} subscription(s) would be renewed.");

            return self::SUCCESS;
        }

        $this->info("Renewed {$renewed} subscription(s) and recorded their invoices.");

        return self::SUCCESS;
    }
}
