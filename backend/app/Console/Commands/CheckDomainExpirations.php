<?php

namespace App\Console\Commands;

use App\Events\DomainExpiring;
use App\Models\Domain;
use Illuminate\Console\Command;

class CheckDomainExpirations extends Command
{
    protected $signature = 'omnex:check-domain-expirations {--days=}';

    protected $description = 'Dispatch DomainExpiring events for domains nearing their expiration date.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('omnex.domain.expiration_warning_days', 30));

        $threshold = now()->addDays($days);

        $domains = Domain::withoutTenancy()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $threshold)
            ->where(fn ($query) => $query
                ->whereNull('expiration_notified_at')
                ->orWhere('expiration_notified_at', '<=', now()->subDays($days))
            )
            ->get();

        foreach ($domains as $domain) {
            $remaining = max(0, (int) now()->diffInDays($domain->expires_at));

            DomainExpiring::dispatch($domain, $remaining);

            $domain->expiration_notified_at = now();
            $domain->save();
        }

        $this->info("Checked {$domains->count()} expiring domain(s).");

        return self::SUCCESS;
    }
}
