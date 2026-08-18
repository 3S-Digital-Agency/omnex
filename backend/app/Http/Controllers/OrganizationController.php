<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesOrganizations;
use App\Http\Resources\MembershipResource;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Support\Audit\AuditLogger;
use App\Support\Organizations\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    use ResolvesOrganizations;

    public function __construct(private OrganizationService $organizations) {}

    public function index(Request $request): JsonResponse
    {
        $memberships = $request->user()->allMemberships()
            ->where('status', 'active')
            ->with(['organization', 'role'])
            ->get();

        return response()->json(['data' => MembershipResource::collection($memberships)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Organization::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $organization = $this->organizations->create($request->user(), $data['name']);

        return response()->json(new OrganizationResource($organization), 201);
    }

    public function show(Request $request, string $organization): JsonResponse
    {
        $organization = $this->resolveScopedOrganization($request, $organization);

        return response()->json(new OrganizationResource($organization));
    }

    public function switch(Request $request, string $organization): JsonResponse
    {
        $organization = Organization::findOrFail($organization);

        $membership = $request->user()->allMemberships()
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->with('role')
            ->first();

        abort_unless($membership !== null, 403, 'You are not a member of this organization.');

        AuditLogger::record('organization.switched', 'organization', $organization->id);

        return response()->json([
            'active_organization' => new OrganizationResource($organization),
            'role' => $membership->role ? [
                'id' => $membership->role->id,
                'name' => $membership->role->name,
                'key' => $membership->role->key,
            ] : null,
        ]);
    }
}
