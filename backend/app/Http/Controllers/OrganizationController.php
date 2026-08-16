<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesOrganizations;
use App\Http\Resources\MembershipResource;
use App\Http\Resources\OrganizationResource;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrganizationController extends Controller
{
    use ResolvesOrganizations;

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

        $organization = DB::transaction(function () use ($request, $data) {
            $organization = Organization::create([
                'name' => $data['name'],
                'plan_tier' => 'free',
                'status' => 'active',
            ]);

            Membership::create([
                'organization_id' => $organization->id,
                'user_id' => $request->user()->id,
                'role_id' => Role::where('key', 'owner')->firstOrFail()->id,
                'status' => 'active',
                'joined_at' => now(),
            ]);

            return $organization;
        });

        AuditLogger::record('organization.created', 'organization', $organization->id, null, [
            'name' => $organization->name,
        ]);

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
