<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Support\Cloud\ServerService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Scheduled backups: creates a snapshot for every server whose schedule is due
 * (daily / weekly), then prunes snapshots older than each server's retention
 * window. Scheduled daily (routes/console.php). Use --dry-run to preview.
 */
class RunServerSnapshots extends Command
{
    protected $signature = 'omnex:server-snapshots {--dry-run : Report what would happen without writing anything}';

    protected $description = 'Create due server snapshots and enforce retention policies.';

    public function handle(ServerService $servers): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $due = $servers->serversDueForSnapshot();

        if ($dryRun) {
            foreach ($due as $server) {
                $this->line("  [dry-run] {$server->name} ({$server->snapshot_frequency})");
            }

            $expired = $servers->expiredSnapshots();

            foreach ($expired as $snapshot) {
                $this->line("  [dry-run] would delete snapshot {$snapshot->label} of {$snapshot->server?->name} (retention exceeded)");
            }

            $this->info('Dry run: '.count($due).' snapshot(s) due, '.count($expired).' expired snapshot(s).');

            return self::SUCCESS;
        }

        $created = 0;
        $deleted = 0;

        foreach ($due as $server) {
            app(TenantContext::class)->set($server->organization_id, $server->organization);

            try {
                $servers->createSnapshot($server);
                $created++;
                $this->line("  snapshot created for {$server->name}");
            } catch (\Throwable $e) {
                $this->error("  snapshot failed for {$server->name}: {$e->getMessage()}");
            }
        }

        $deleted = $servers->applyRetention();

        $this->info("Created {$created} snapshot(s), pruned {$deleted} expired snapshot(s).");

        return self::SUCCESS;
    }
}
