<?php

namespace App\Http\Controllers;

use App\Http\Resources\ServerMetricSampleResource;
use App\Http\Resources\ServerOperationResource;
use App\Http\Resources\ServerResource;
use App\Http\Resources\ServerSnapshotResource;
use App\Models\Server;
use App\Models\SshKey;
use App\Support\Cloud\ServerProviderException;
use App\Support\Cloud\ServerProviderRegistry;
use App\Support\Cloud\ServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ServerController extends Controller
{
    public function __construct(private ServerService $servers) {}

    public function providers(Request $request): JsonResponse
    {
        $this->authorize('cloud.read');

        return response()->json(['data' => $this->servers->providers()]);
    }

    /**
     * Live verification of every configured provider (or one named provider)
     * — read-only authenticated calls, nothing is provisioned or billed.
     * Used to validate real tokens before provisioning.
     */
    public function verifyProviders(Request $request): JsonResponse
    {
        $this->authorize('cloud.read');

        $only = $request->query('provider');
        $registry = app(ServerProviderRegistry::class);

        $providers = collect($registry->all())
            ->filter(fn (array $provider) => $only === null || $provider['name'] === $only)
            ->values();

        $results = $providers->map(fn (array $info) => array_merge(
            $info,
            ['verified' => $registry->get($info['name'])->verify()],
        ))->values()->all();

        return response()->json(['data' => $results]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('cloud.read');

        $servers = Server::query()
            ->withCount('operations')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => ServerResource::collection($servers)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('cloud.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9-]*[a-z0-9]$/'],
            'region' => ['sometimes', 'string', Rule::in(config('omnex.cloud.regions', ['fsn1']))],
            'plan' => ['sometimes', 'string', Rule::in(config('omnex.cloud.plans', ['cpx11']))],
            'image' => ['sometimes', 'string', Rule::in(config('omnex.cloud.images', ['ubuntu-24.04']))],
            'ssh_key' => ['sometimes', 'string', 'max:255'],
            'ssh_key_id' => ['sometimes', 'nullable', 'string', 'max:36'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:32'],
            'provider' => ['sometimes', 'string', 'max:32'],
        ]);

        try {
            $server = $this->servers->create(
                $data['name'],
                $data['region'] ?? '',
                $data['plan'] ?? '',
                $data['image'] ?? '',
                $data['ssh_key'] ?? '',
                $data['tags'] ?? [],
                $data['provider'] ?? null,
                $data['ssh_key_id'] ?? null,
            );
        } catch (ServerProviderException $e) {
            abort(503, $e->getMessage());
        }

        return response()->json(new ServerResource($server->loadCount('operations')), 201);
    }

    public function show(Request $request, string $server): JsonResponse
    {
        $this->authorize('cloud.read');

        return response()->json(new ServerResource(Server::withCount('operations')->findOrFail($server)));
    }

    public function update(Request $request, string $server): JsonResponse
    {
        $this->authorize('cloud.manage');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9-]*[a-z0-9]$/'],
            'ssh_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:32'],
            'snapshot_frequency' => ['sometimes', 'string', Rule::in(['disabled', 'daily', 'weekly'])],
            'snapshot_retention_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        return response()->json(new ServerResource(
            $this->servers->update(Server::findOrFail($server), $data)->loadCount('operations')
        ));
    }

    public function destroy(Request $request, string $server): JsonResponse
    {
        $this->authorize('cloud.manage');

        try {
            $this->servers->delete(Server::findOrFail($server));
        } catch (ServerProviderException $e) {
            abort(503, $e->getMessage());
        }

        return response()->json(null, 204);
    }

    /**
     * Securely copy a saved key onto this server through its provider.
     * Returns the outcome (installed / unsupported) in the operation trail.
     */
    public function installSshKey(Request $request, string $server): JsonResponse
    {
        $this->authorize('cloud.manage');

        $data = $request->validate([
            'ssh_key_id' => ['required', 'string', 'max:36'],
        ]);

        $server = Server::findOrFail($server);
        $key = SshKey::findOrFail($data['ssh_key_id']);

        try {
            $result = $this->servers->installSshKey($server, $key);
        } catch (ServerProviderException $e) {
            abort(503, $e->getMessage());
        }

        return response()->json([
            'status' => $result['status'] ?? 'installed',
            'detail' => $result['detail'] ?? null,
        ], 201);
    }

    public function operations(Request $request, string $server): JsonResponse
    {
        $this->authorize('cloud.read');

        return response()->json([
            'data' => ServerOperationResource::collection($this->servers->operations(Server::findOrFail($server))),
        ]);
    }

    public function start(Request $request, string $server): JsonResponse
    {
        $this->authorize('cloud.manage');

        return response()->json(new ServerOperationResource(
            $this->servers->start(Server::findOrFail($server))
        ), 201);
    }

    public function stop(Request $request, string $server): JsonResponse
    {
        $this->authorize('cloud.manage');

        return response()->json(new ServerOperationResource(
            $this->servers->stop(Server::findOrFail($server))
        ), 201);
    }

    public function reboot(Request $request, string $server): JsonResponse
    {
        $this->authorize('cloud.manage');

        return response()->json(new ServerOperationResource(
            $this->servers->reboot(Server::findOrFail($server))
        ), 201);
    }

    public function rebuild(Request $request, string $server): JsonResponse
    {
        $this->authorize('cloud.manage');

        $data = $request->validate([
            'image' => ['sometimes', 'string', Rule::in(config('omnex.cloud.images', ['ubuntu-24.04']))],
        ]);

        return response()->json(new ServerOperationResource(
            $this->servers->rebuild(Server::findOrFail($server), $data['image'] ?? '')
        ), 201);
    }

    public function snapshots(Request $request, string $server): JsonResponse
    {
        $this->authorize('cloud.read');

        return response()->json([
            'data' => ServerSnapshotResource::collection($this->servers->snapshots(Server::findOrFail($server))),
        ]);
    }

    public function storeSnapshot(Request $request, string $server): JsonResponse
    {
        $this->authorize('cloud.manage');

        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:255'],
        ]);

        try {
            $snapshot = $this->servers->createSnapshot(
                Server::findOrFail($server),
                $data['label'] ?? null,
            );
        } catch (ServerProviderException $e) {
            abort(503, $e->getMessage());
        }

        return response()->json(new ServerSnapshotResource($snapshot), 201);
    }

    public function destroySnapshot(Request $request, string $server, string $snapshot): JsonResponse
    {
        $this->authorize('cloud.manage');

        $server = Server::findOrFail($server);

        try {
            $this->servers->deleteSnapshot($server, $server->snapshots()->findOrFail($snapshot));
        } catch (ServerProviderException $e) {
            abort(503, $e->getMessage());
        }

        return response()->json(null, 204);
    }

    public function metricsHistory(Request $request, string $server): JsonResponse
    {
        $this->authorize('cloud.read');

        $limit = max(1, min(240, (int) $request->query('limit', config('omnex.cloud.metrics_history_limit', 60))));

        return response()->json([
            'data' => ServerMetricSampleResource::collection(
                $this->servers->metricsHistory(Server::findOrFail($server), $limit)
            ),
        ]);
    }

    /**
     * Server-Sent Events stream of `server.metrics` samples for one server.
     * A sample is emitted immediately, then every `interval` seconds, until
     * `sse_max_seconds` elapses or the client disconnects (the frontend
     * reconnects automatically). Every emitted sample is also persisted so
     * `metrics/history` can serve it.
     */
    public function metricsStream(Request $request, string $server): StreamedResponse
    {
        $this->authorize('cloud.read');

        $server = Server::findOrFail($server);
        $interval = max(1, min(30, (int) $request->query('interval', config('omnex.cloud.metrics_sse_interval', 5))));
        $maxSeconds = max(0, (int) config('omnex.cloud.metrics_sse_max_seconds', 120));

        return response()->stream(function () use ($server, $interval, $maxSeconds) {
            $started = microtime(true);

            while (true) {
                $metrics = $this->servers->metrics($server);
                $this->servers->recordMetricsSample($server, $metrics);

                echo "event: server.metrics\n";
                echo 'data: '.json_encode(array_merge(
                    ['server_id' => $server->id, 'sampled_at' => now()->toIso8601String()],
                    $metrics,
                ), JSON_THROW_ON_ERROR)."\n\n";

                $remaining = $maxSeconds - (microtime(true) - $started);

                // Break before flushing so a final sample stays in the
                // caller's buffer (test capture) and is not lost.
                if ($remaining <= 0 || connection_aborted()) {
                    break;
                }

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                sleep((int) min($interval, max(1, $remaining)));
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
