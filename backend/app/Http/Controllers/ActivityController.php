<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\Activity\ActivityPresenter;
use App\Support\Streams\StreamBroker;
use App\Support\Streams\StreamChannels;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityController extends Controller
{
    /**
     * Aggregated activity feed. Backed by the persisted event stream
     * (audit_logs) for Phase 2; deployments, alerts and incidents join the
     * same stream in later phases. Supports an incremental cursor so the
     * client can poll only for new events.
     *
     * GET /api/v1/activity?since=<id>&per_page=50
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('audit.read');

        $since = (int) $request->query('since', 0);

        $logs = AuditLog::query()
            ->with('user')
            ->orderByDesc('id')
            ->when($since > 0, fn ($query) => $query->where('id', '>', $since))
            ->limit(min((int) $request->query('per_page', 50), 100))
            ->get();

        return response()->json([
            'data' => $logs->map(fn (AuditLog $log) => ActivityPresenter::toArray($log))->values(),
            'latest_id' => $logs->max('id') ?? $since,
        ]);
    }

    /**
     * Server-Sent Events stream of `activity.created` frames for the active
     * tenant. AuditLogger queues events as they are recorded; this endpoint
     * drains and writes them. The client reconnects after `sse_max_seconds`;
     * comment frames keep intermediaries from buffering the connection.
     */
    public function stream(Request $request): StreamedResponse
    {
        $this->authorize('audit.read');

        $organizationId = app(TenantContext::class)->id();
        $channel = StreamChannels::activity($organizationId);
        $broker = app(StreamBroker::class);
        $maxSeconds = (int) config('omnex.activity.sse_max_seconds', 60);
        $heartbeat = max(1, (int) config('omnex.activity.sse_heartbeat_seconds', 15));

        return response()->stream(function () use ($channel, $broker, $maxSeconds, $heartbeat) {
            $started = microtime(true);

            $emit = function (array $event): void {
                echo "event: activity.created\n";
                echo 'data: '.json_encode($event)."\n\n";
            };

            while (true) {
                $remaining = $maxSeconds - (microtime(true) - $started);

                if ($remaining <= 0) {
                    // Final non-blocking pass so buffered events (in-process
                    // driver) are still flushed before the connection closes.
                    $broker->listen($channel, $emit, 0);
                    break;
                }

                $broker->listen($channel, $emit, (int) min($heartbeat, max(1, $remaining)));

                // Keep-alive comment so proxies do not buffer the stream.
                echo ": ping\n\n";

                if (connection_aborted()) {
                    break;
                }

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
