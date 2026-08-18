<?php

namespace App\Http\Controllers;

use App\Http\Resources\DnsHistoryResource;
use App\Http\Resources\DnsRecordResource;
use App\Http\Resources\PropagationCheckResource;
use App\Models\DnsZone;
use App\Models\Domain;
use App\Support\Domains\DnsPropagationService;
use App\Support\Domains\DnsService;
use App\Support\Domains\DnsTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DnsController extends Controller
{
    public function __construct(private DnsService $dns, private DnsPropagationService $propagation) {}

    public function providers(Request $request): JsonResponse
    {
        $this->authorize('dns.read');

        return response()->json(['data' => $this->dns->providers()]);
    }

    public function activeProvider(Request $request): JsonResponse
    {
        $this->authorize('dns.read');

        return response()->json(['data' => $this->dns->activeProvider()]);
    }

    public function setProvider(Request $request): JsonResponse
    {
        $this->authorize('dns.manage');

        $validated = $request->validate(['name' => ['required', 'string', 'max:32']]);

        return response()->json(['data' => $this->dns->setProvider($validated['name'])]);
    }

    public function index(Request $request, string $domain): JsonResponse
    {
        $this->authorize('dns.read');

        $zone = $this->resolveZone($domain);

        return response()->json([
            'zone' => [
                'id' => $zone->id,
                'provider' => $zone->provider,
                'status' => $zone->status,
            ],
            'data' => DnsRecordResource::collection($this->dns->records($zone)),
        ]);
    }

    public function store(Request $request, string $domain): JsonResponse
    {
        $this->authorize('dns.manage');

        $zone = $this->resolveZone($domain);
        $data = $this->validateRecordPayload($request);

        $record = $this->dns->createRecord($zone, $data, $request->user());

        return response()->json(new DnsRecordResource($record), 201);
    }

    public function update(Request $request, string $domain, string $record): JsonResponse
    {
        $this->authorize('dns.manage');

        $zone = $this->resolveZone($domain);
        $record = $zone->records()->findOrFail($record);
        $data = $this->validateRecordPayload($request);

        return response()->json(new DnsRecordResource(
            $this->dns->updateRecord($zone, $record, $data, $request->user())
        ));
    }

    public function destroy(Request $request, string $domain, string $record): JsonResponse
    {
        $this->authorize('dns.manage');

        $zone = $this->resolveZone($domain);
        $record = $zone->records()->findOrFail($record);

        $this->dns->deleteRecord($zone, $record, $request->user());

        return response()->json(['message' => 'DNS record deleted.']);
    }

    public function history(Request $request, string $domain): JsonResponse
    {
        $this->authorize('dns.read');

        $zone = $this->resolveZone($domain);

        return response()->json(['data' => DnsHistoryResource::collection($this->dns->history($zone))]);
    }

    public function rollback(Request $request, string $domain, string $history): JsonResponse
    {
        $this->authorize('dns.manage');

        $zone = $this->resolveZone($domain);
        $entry = $zone->history()->findOrFail($history);

        $this->dns->rollback($zone, $entry, $request->user());

        return response()->json(['data' => DnsRecordResource::collection($this->dns->records($zone))]);
    }

    public function export(Request $request, string $domain): JsonResponse
    {
        $this->authorize('dns.read');

        $zone = $this->resolveZone($domain);

        return response()->json(['zone_file' => $this->dns->export($zone)]);
    }

    public function import(Request $request, string $domain): JsonResponse
    {
        $this->authorize('dns.manage');

        $zone = $this->resolveZone($domain);

        $data = $request->validate([
            'zone_file' => ['required', 'string', 'max:100000'],
        ]);

        $records = $this->dns->import($zone, $data['zone_file'], $request->user());

        return response()->json(['data' => DnsRecordResource::collection($records)]);
    }

    public function applyTemplate(Request $request, string $domain, string $template): JsonResponse
    {
        $this->authorize('dns.manage');

        $zone = $this->resolveZone($domain);

        if (! in_array($template, DnsTemplates::names(), true)) {
            throw ValidationException::withMessages(['template' => ["Unknown DNS template [{$template}]."]]);
        }

        $records = $this->dns->applyTemplate($zone, $template, $request->user());

        return response()->json(['data' => DnsRecordResource::collection($records)], 201);
    }

    public function dnssec(Request $request, string $domain): JsonResponse
    {
        $this->authorize('dns.read');

        $zone = $this->resolveZone($domain);

        return response()->json($this->dns->dnssec($zone));
    }

    public function enableDnssec(Request $request, string $domain): JsonResponse
    {
        $this->authorize('dns.manage');

        $zone = $this->resolveZone($domain);

        return response()->json($this->dns->enableDnssec($zone));
    }

    public function disableDnssec(Request $request, string $domain): JsonResponse
    {
        $this->authorize('dns.manage');

        $zone = $this->resolveZone($domain);

        return response()->json($this->dns->disableDnssec($zone));
    }

    public function propagation(Request $request, string $domain): JsonResponse
    {
        $this->authorize('dns.read');

        $zone = $this->resolveZone($domain);

        $status = $this->propagation->status($zone);

        return response()->json([
            'domain' => $status['domain'],
            'nameservers' => $status['nameservers'],
            'checked_at' => $status['checked_at'],
            'data' => PropagationCheckResource::collection($status['data']),
            'summary' => $status['summary'],
        ]);
    }

    public function runPropagationCheck(Request $request, string $domain): JsonResponse
    {
        $this->authorize('dns.manage');

        $zone = $this->resolveZone($domain);

        $status = $this->propagation->check($zone);

        return response()->json([
            'domain' => $status['domain'],
            'nameservers' => $status['nameservers'],
            'checked_at' => $status['checked_at'],
            'data' => PropagationCheckResource::collection($status['data']),
            'summary' => $status['summary'],
        ]);
    }

    private function resolveZone(string $domain): DnsZone
    {
        $domain = Domain::with('zone')->findOrFail($domain);

        if ($domain->zone === null) {
            abort(404, 'DNS zone not found.');
        }

        return $domain->zone;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRecordPayload(Request $request): array
    {
        return $request->validate([
            'type' => ['required', 'string', 'max:16'],
            'name' => ['nullable', 'string', 'max:253'],
            'content' => ['required', 'string'],
            'ttl' => ['nullable', 'integer', 'min:0'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'proxied' => ['nullable', 'boolean'],
        ]);
    }
}
