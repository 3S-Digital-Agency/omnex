<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesOrganizations;
use App\Http\Resources\MembershipResource;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberController extends Controller
{
    use ResolvesOrganizations;

    public function index(Request $request, string $organization): JsonResponse
    {
        $organization = $this->resolveScopedOrganization($request, $organization);

        $memberships = $organization->memberships()
            ->with(['user', 'role'])
            ->get();

        return response()->json(['data' => MembershipResource::collection($memberships)]);
    }

    public function updateRole(Request $request, string $organization, string $membership): JsonResponse
    {
        $organization = $this->resolveScopedOrganization($request, $organization);
        $this->authorize('manageMembers', $organization);

        $data = $request->validate([
            'role_id' => ['required', 'uuid'],
        ]);

        $membership = $organization->memberships()->findOrFail($membership);

        $previousRole = $membership->role_id;
        $membership->role_id = $data['role_id'];
        $membership->save();

        AuditLogger::record('member.role_changed', 'membership', $membership->id, [
            'role_id' => $previousRole,
        ], [
            'role_id' => $membership->role_id,
        ]);

        return response()->json(new MembershipResource($membership->load(['user', 'role'])));
    }

    public function destroy(Request $request, string $organization, string $membership): JsonResponse
    {
        $organization = $this->resolveScopedOrganization($request, $organization);
        $this->authorize('manageMembers', $organization);

        $membership = $organization->memberships()->with(['user', 'role'])->findOrFail($membership);

        if ($membership->role?->key === 'owner') {
            $ownerCount = $organization->memberships()
                ->whereHas('role', fn ($query) => $query->where('key', 'owner'))
                ->count();

            if ($ownerCount <= 1) {
                abort(422, 'The last owner cannot be removed.');
            }
        }

        $email = $membership->user?->email;
        $membership->delete();

        AuditLogger::record('member.removed', 'membership', $membership->id, [
            'email' => $email,
        ]);

        return response()->json(['message' => 'Member removed.']);
    }
}
