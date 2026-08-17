<?php

namespace App\Http\Controllers;

use App\Http\Resources\LandingPageResource;
use App\Models\LandingPage;
use App\Support\Marketing\LandingPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LandingPageController extends Controller
{
    public function __construct(private readonly LandingPageService $pages) {}

    /**
     * Public entry point for a campaign page. Only published pages are
     * served — a draft, a deleted page and an unknown slug all answer 404 so
     * the public site can safely treat them the same way.
     */
    public function show(string $slug): JsonResponse
    {
        $page = LandingPage::query()->published()->where('slug', $slug)->first();

        abort_unless($page !== null, 404, 'Page not found.');

        return (new LandingPageResource($page))->response();
    }

    /** Back-office listing for platform owners. */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        $pages = LandingPage::query()
            ->ofType($request->input('type'))
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('updated_at')
            ->get();

        return LandingPageResource::collection($pages)->response();
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        $page = $this->pages->create($request->all());

        return (new LandingPageResource($page))->response()->setStatusCode(201);
    }

    public function update(Request $request, LandingPage $landingPage): JsonResponse
    {
        $this->authorizeOwner($request);

        $page = $this->pages->update($landingPage, $request->all());

        return (new LandingPageResource($page))->response();
    }

    public function destroy(Request $request, LandingPage $landingPage): JsonResponse
    {
        $this->authorizeOwner($request);

        $landingPage->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Campaign pages are a platform-level asset (like contact leads): only a
     * user who owns at least one organization may manage them.
     */
    private function authorizeOwner(Request $request): void
    {
        $isOwner = $request->user()->memberships()
            ->whereHas('role', fn ($query) => $query->where('key', 'owner'))
            ->exists();

        abort_unless($isOwner, 403, 'Owner role required.');
    }
}
