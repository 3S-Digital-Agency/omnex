<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('omnex:check-domain-expirations')->daily();
Schedule::command('omnex:billing-renewals')->daily();
// Scheduled server snapshots + retention pruning (Phase 8). The command
// itself is a no-op when no server has snapshots enabled.
Schedule::command('omnex:server-snapshots')->daily();
