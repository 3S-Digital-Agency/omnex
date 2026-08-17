<?php

namespace App\Support\Auth;

use App\Http\Resources\InvitationResource;
use App\Http\Resources\MembershipResource;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Models\Invitation;
use App\Models\User;

/**
 * Builds the complete auth-session payload (token, user, memberships, active
 * organization, permissions, pending invitations) shared by every sign-in
 * path: password, MFA, passkeys/WebAuthn, cross-device and device verify.
 */
final class AuthSessionResponse
{
    public static function make(User $user, ?string $tokenName = null): array
    {
        $user->last_login_at = now();
        $user->save();

        $memberships = $user->allMemberships()->with(['role', 'organization'])->get();
        $active = $user->allMemberships()
            ->where('status', 'active')
            ->with('organization')
            ->first();

        return [
            'token' => TokenIssuer::issue($user, request(), $tokenName),
            'user' => new UserResource($user),
            'memberships' => MembershipResource::collection($memberships),
            'active_organization' => $active?->organization
                ? new OrganizationResource($active->organization)
                : null,
            'permissions' => $user->permissionKeys(),
            'pending_invitations' => InvitationResource::collection(
                Invitation::withoutTenancy()
                    ->where('email', $user->email)
                    ->where('status', 'pending')
                    ->with(['organization', 'role'])
                    ->get()
            ),
        ];
    }
}
