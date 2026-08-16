<?php

namespace App\Http\Controllers;

use App\Http\Resources\DomainResource;
use App\Models\Domain;
use App\Support\Domains\DomainProviderException;
use App\Support\Domains\DomainService;
use App\Support\Domains\DomainUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DomainController extends Controller
{
    public function __construct(private DomainService $domains) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('domains.read');

        $domains = Domain::query()->with('zone')->orderBy('name')->get();

        return response()->json(['data' => DomainResource::collection($domains)]);
    }

    public function providers(Request $request): JsonResponse
    {
        $this->authorize('domains.read');

        return response()->json(['data' => $this->domains->providers()]);
    }

    public function search(Request $request): JsonResponse
    {
        $this->authorize('domains.read');

        $data = $request->validate([
            'query' => ['required', 'string', 'max:63'],
            'tlds' => ['sometimes', 'array'],
            'tlds.*' => ['string', 'max:24'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $provider = $this->resolveProvider($request);

        try {
            return response()->json([
                'data' => $this->domains->search($data['query'], $data['tlds'] ?? [], $provider),
            ]);
        } catch (DomainProviderException $e) {
            abort(503, $e->getMessage());
        }
    }

    public function check(Request $request): JsonResponse
    {
        $this->authorize('domains.read');

        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $provider = $this->resolveProvider($request);

        try {
            return response()->json($this->domains->check($data['domain'], $provider));
        } catch (DomainProviderException $e) {
            abort(503, $e->getMessage());
        }
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('domains.manage');

        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'years' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $provider = $this->resolveProvider($request);

        try {
            $domain = $this->domains->register(
                $data['domain'],
                ['years' => $data['years'] ?? config('nexus.domain.default_registration_years')],
                $request->user(),
                $provider,
            );
        } catch (DomainUnavailableException $e) {
            abort(422, $e->getMessage());
        } catch (DomainProviderException $e) {
            abort(503, $e->getMessage());
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

        try {
            $renewed = $this->domains->renew($domain, $data['years'] ?? 1);
        } catch (DomainProviderException $e) {
            abort(503, $e->getMessage());
        }

        return response()->json(new DomainResource($renewed));
    }

    public function transfer(Request $request): JsonResponse
    {
        $this->authorize('domains.manage');

        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'auth_code' => ['required', 'string', 'min:6'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $provider = $this->resolveProvider($request);

        try {
            $domain = $this->domains->transfer($data['domain'], $data['auth_code'], $request->user(), $provider);
        } catch (DomainUnavailableException $e) {
            abort(422, $e->getMessage());
        } catch (DomainProviderException $e) {
            abort(503, $e->getMessage());
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
        } catch (DomainProviderException $e) {
            abort(503, $e->getMessage());
        }

        return response()->json(new DomainResource($domain));
    }

    /**
     * Validate an optional provider choice: it must be a known registrar and
     * must be configured, otherwise the request is rejected with a 422.
     */
    private function resolveProvider(Request $request): ?string
    {
        $name = $request->input('provider');

        if ($name === null || $name === '') {
            return null;
        }

        $provider = collect($this->domains->providers())->firstWhere('name', $name);

        if ($provider === null) {
            throw ValidationException::withMessages(['provider' => ["Unknown domain provider [{$name}]."]]);
        }

        if (! $provider['configured']) {
            throw ValidationException::withMessages(['provider' => ["The [{$provider['label']}] registrar is not configured."]]);
        }

        return $name;
    }
}
