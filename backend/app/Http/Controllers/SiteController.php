<?php

namespace App\Http\Controllers;

use App\Http\Resources\SiteDeploymentResource;
use App\Http\Resources\SiteResource;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Support\Sites\SiteProviderException;
use App\Support\Sites\SiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SiteController extends Controller
{
    public function __construct(private SiteService $sites) {}

    public function providers(Request $request): JsonResponse
    {
        $this->authorize('sites.read');

        return response()->json(['data' => $this->sites->providers()]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('sites.read');

        $sites = Site::query()
            ->withCount('deployments')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => SiteResource::collection($sites)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('sites.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'framework' => ['required', 'string', Rule::in(config('omnex.sites.frameworks', ['static', 'laravel', 'next']))],
            'git_url' => ['required', 'url', 'max:2048'],
            'git_branch' => ['sometimes', 'string', 'max:255'],
            'environment_variables' => ['sometimes', 'array'],
            'environment_variables.*' => ['string'],
            'provider' => ['sometimes', 'string', 'max:32'],
        ]);

        try {
            $site = $this->sites->create(
                $data['name'],
                $data['framework'],
                $data['git_url'],
                $data['git_branch'] ?? (string) config('omnex.sites.default_branch', 'main'),
                $this->stringifyEnvironment($data['environment_variables'] ?? []),
                $data['provider'] ?? null,
            );
        } catch (SiteProviderException $e) {
            abort(503, $e->getMessage());
        }

        return response()->json(new SiteResource($site->loadCount('deployments')), 201);
    }

    public function show(Request $request, string $site): JsonResponse
    {
        $this->authorize('sites.read');

        return response()->json(new SiteResource(Site::withCount('deployments')->findOrFail($site)));
    }

    public function update(Request $request, string $site): JsonResponse
    {
        $this->authorize('sites.manage');

        $site = Site::findOrFail($site);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'framework' => ['sometimes', 'string', Rule::in(config('omnex.sites.frameworks', ['static', 'laravel', 'next']))],
            'git_url' => ['sometimes', 'url', 'max:2048'],
            'git_branch' => ['sometimes', 'string', 'max:255'],
            'environment_variables' => ['sometimes', 'array'],
            'environment_variables.*' => ['string'],
        ]);

        if (array_key_exists('environment_variables', $data)) {
            $data['environment_variables'] = $this->stringifyEnvironment($data['environment_variables']);
        }

        return response()->json(new SiteResource($this->sites->update($site, $data)->loadCount('deployments')));
    }

    public function destroy(Request $request, string $site): JsonResponse
    {
        $this->authorize('sites.manage');

        try {
            $this->sites->delete(Site::findOrFail($site));
        } catch (SiteProviderException $e) {
            abort(503, $e->getMessage());
        }

        return response()->json(null, 204);
    }

    public function deployments(Request $request, string $site): JsonResponse
    {
        $this->authorize('sites.read');

        return response()->json([
            'data' => SiteDeploymentResource::collection($this->sites->deployments(Site::findOrFail($site))),
        ]);
    }

    public function deploy(Request $request, string $site): JsonResponse
    {
        $this->authorize('sites.manage');

        $site = Site::findOrFail($site);

        try {
            $deployment = $this->sites->deploy($site);
        } catch (SiteProviderException $e) {
            abort(503, $e->getMessage());
        }

        $status = $deployment->status === 'failed' ? 200 : 201;

        return response()->json(new SiteDeploymentResource($deployment), $status);
    }

    public function showDeployment(Request $request, string $site, string $deployment): JsonResponse
    {
        $this->authorize('sites.read');

        $site = Site::findOrFail($site);

        return response()->json(new SiteDeploymentResource(
            SiteDeployment::where('site_id', $site->id)->findOrFail($deployment)
        ));
    }

    public function rollback(Request $request, string $site, string $deployment): JsonResponse
    {
        $this->authorize('sites.manage');

        $site = Site::findOrFail($site);
        $deployment = SiteDeployment::where('site_id', $site->id)->findOrFail($deployment);

        try {
            $deployment = $this->sites->rollback($site, $deployment);
        } catch (SiteProviderException $e) {
            abort(503, $e->getMessage());
        }

        return response()->json(new SiteDeploymentResource($deployment));
    }

    /**
     * @param  array<string, string>  $environment
     * @return array<string, string>
     */
    private function stringifyEnvironment(array $environment): array
    {
        return collect($environment)->mapWithKeys(
            fn ($value, $key) => [(string) $key => (string) $value]
        )->all();
    }
}
