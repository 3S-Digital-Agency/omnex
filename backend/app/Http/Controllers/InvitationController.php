<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesOrganizations;
use App\Http\Resources\InvitationResource;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InvitationController extends Controller
{
    use ResolvesOrganizations;

    public function index(Request $request, string $organization): JsonResponse
    {
        $organization = $this->resolveScopedOrganization($request, $organization);

        $invitations = $organization->invitations()
            ->where('status', 'pending')
            ->with('role')
            ->get();

        return response()->json(['data' => InvitationResource::collection($invitations)]);
    }

    public function store(Request $request, string $organization): JsonResponse
    {
        $organization = $this->resolveScopedOrganization($request, $organization);
        $this->authorize('manageInvitations', $organization);

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role_id' => ['required', 'uuid'],
        ]);

        $email = strtolower($data['email']);

        $existingUser = User::where('email', $email)->first();
        if ($existingUser !== null && $existingUser->allMemberships()
            ->where('organization_id', $organization->id)
            ->exists()) {
            abort(422, 'This user is already a member of the organization.');
        }

        $invitation = Invitation::create([
            'organization_id' => $organization->id,
            'email' => $email,
            'role_id' => $data['role_id'],
            'token' => Str::random(64),
            'status' => 'pending',
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        AuditLogger::record('member.invited', 'invitation', $invitation->id, null, [
            'email' => $invitation->email,
        ]);

        return response()->json(new InvitationResource($invitation->load('role')), 201);
    }

    public function cancel(Request $request, string $organization, string $invitation): JsonResponse
    {
        $organization = $this->resolveScopedOrganization($request, $organization);
        $this->authorize('manageInvitations', $organization);

        $invitation = $organization->invitations()->findOrFail($invitation);
        $invitation->update(['status' => 'cancelled']);

        AuditLogger::record('member.invitation_cancelled', 'invitation', $invitation->id);

        return response()->json(['message' => 'Invitation cancelled.']);
    }

    public function accept(Request $request, string $invitation): JsonResponse
    {
        // Accepted by id (the authenticated user must match the invited email).
        // The stored token is reserved for future email-link flows.
        $invitation = Invitation::withoutTenancy()
            ->where('id', $invitation)
            ->where('status', 'pending')
            ->firstOrFail();

        if ($invitation->expires_at->isPast()) {
            $invitation->update(['status' => 'expired']);
            abort(410, 'This invitation has expired.');
        }

        $user = $request->user();

        if (strtolower($user->email) !== strtolower($invitation->email)) {
            abort(403, 'This invitation was sent to a different email address.');
        }

        if ($user->allMemberships()->where('organization_id', $invitation->organization_id)->exists()) {
            abort(422, 'You are already a member of this organization.');
        }

        Membership::create([
            'organization_id' => $invitation->organization_id,
            'user_id' => $user->id,
            'role_id' => $invitation->role_id,
            'status' => 'active',
            'invited_by' => $invitation->invited_by,
            'joined_at' => now(),
        ]);

        $invitation->update(['status' => 'accepted', 'accepted_at' => now()]);

        AuditLogger::record('member.invitation_accepted', 'invitation', $invitation->id, null, [
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'Invitation accepted.',
            'organization_id' => $invitation->organization_id,
        ]);
    }
}
