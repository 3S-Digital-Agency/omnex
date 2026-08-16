<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use App\Support\Streams\StreamBroker;
use App\Support\Streams\StreamChannels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('notifications.read');

        $query = $request->user()->notifications()->orderByDesc('created_at');

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($severity = $request->query('severity')) {
            $query->where('severity', $severity);
        }

        if ($request->has('unread')) {
            $request->boolean('unread')
                ? $query->whereNull('read_at')
                : $query->whereNotNull('read_at');
        }

        $page = $query->paginate(
            min(100, max(1, (int) $request->query('per_page', 10))),
        );

        return response()->json([
            'data' => NotificationResource::collection($page->items()),
            'unread' => $request->user()->notifications()->whereNull('read_at')->count(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $this->authorize('notifications.read');

        $notification = $request->user()->notifications()->findOrFail($notification);

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(new NotificationResource($notification));
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->authorize('notifications.read');

        $updated = $request->user()->notifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['updated' => $updated]);
    }

    /**
     * Server-Sent Events stream of `notification.created` frames for the
     * authenticated user. The client reconnects after `sse_max_seconds`;
     * comment frames keep intermediaries from buffering the connection.
     */
    public function stream(Request $request): StreamedResponse
    {
        $this->authorize('notifications.read');

        $channel = StreamChannels::notifications($request->user()->id);
        $broker = app(StreamBroker::class);
        $maxSeconds = (int) config('omnex.notifications.sse_max_seconds', 60);
        $heartbeat = max(1, (int) config('omnex.notifications.sse_heartbeat_seconds', 15));

        return response()->stream(function () use ($channel, $broker, $maxSeconds, $heartbeat) {
            $started = microtime(true);

            $emit = function (array $event): void {
                echo "event: notification.created\n";
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
