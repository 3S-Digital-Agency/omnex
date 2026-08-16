<?php

namespace App\Http\Controllers;

use App\Http\Resources\DomainResource;
use App\Models\Domain;
use App\Support\Domains\DomainService;
use App\Support\Domains\DomainUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DomainController extends Controller
{
    public function __construct(private DomainService $domains)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('domains.read');

        $domains = Domain::query()->with('zone')->orderBy('name')->get();

        return response()->json(['data' => DomainResource::collection($domains)]);
    }

    public function search(Request $request): JsonResponse
    {
        $this->authorize('domains.read');

        $data = $request->validate([
            'query' => ['required', 'string', 'max:63'],
            'tlds' => ['sometimes', 'array'],
            'tlds.*' => ['string', 'max:24'],
        ]);

        return response()->json([
            'data' => $this->domains->search($data['query'], $data['tlds'] ?? []),
        ]);
    }

    public function check(Request $request): JsonResponse
    {
        $this->authorize('domains.read');

        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
        ]);

        return response()->json($this->domains->check($data['domain']));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('domains.manage');

        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'years' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);

        try {
            $domain = $this->domains->register(
                $data['domain'],
                ['years' => $data['years'] ?? config('nexus.domain.default_registration_years')],
                $request->user(),
            );
        } catch (DomainUnavailableException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(new DomainResource($domain), 201);
    }

    public function show(Request $request, string $domain): JsonResponse
    {
        $this->authorize('domains.read');

        $domain = Domain::with('zone')->findOrFail($domain);

        return response()->json(new DomainResource($domain));
    }

    public function renew(Request $request, string $domain): JsonResponse
    {
        $this->authorize('domains.manage');

        $data = $request->validate([
            'years' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);

        $domain = Domain::findOrFail($domain);

        return response()->json(new DomainResource(
            $this->domains->renew($domain, $data['years'] ?? 1)
        ));
    }

    public function transfer(Request $request): JsonResponse
    {
        $this->authorize('domains.manage');

        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'auth_code' => ['required', 'string', 'min:6'],
        ]);

        try {
            $domain = $this->domains->transfer($data['domain'], $data['auth_code'], $request->user());
        } catch (DomainUnavailableException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(new DomainResource($domain), 201);
    }

    public function update(Request $request, string $domain): JsonResponse
    {
        $this->authorize('domains.manage');

        $data = $request->validate([
            'auto_renew' => ['sometimes', 'boolean'],
            'privacy_protection' => ['sometimes', 'boolean'],
            'transfer_lock' => ['sometimes', 'boolean'],
            'nameservers' => ['sometimes', 'array', 'min:2'],
            'nameservers.*' => ['string', 'max:253'],
            'contacts' => ['sometimes', 'array'],
        ]);

        $domain = Domain::findOrFail($domain);

        try {
            $domain = $this->domains->update($domain, $data);
        } catch (DomainUnavailableException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(new DomainResource($domain));
    }
}
