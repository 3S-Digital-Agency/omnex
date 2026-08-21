<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Public liveness/readiness endpoint — no auth, no tenant context.
 *
 * Used by the Phase 10 deploy pipeline (staging/production health checks),
 * the monitoring workflow and load balancers. It must stay dependency-free
 * (no services, no user context) so it works even while other parts are
 * degraded. Never returns internal details (hosts, credentials, stack).
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $db = 'ok';
        $status = 200;

        try {
            DB::select('select 1');
        } catch (\Throwable) {
            $db = 'unavailable';
            $status = 503;
        }

        return response()->json([
            'status' => $status === 200 ? 'ok' : 'degraded',
            'service' => config('app.name', 'OMNEX'),
            'version' => config('omnex.version'),
            'environment' => app()->environment(),
            'db' => $db,
            'time' => now()->toIso8601String(),
        ], $status);
    }
}
