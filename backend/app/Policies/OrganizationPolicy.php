<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    /** Any authenticated user may create an organization (fresh onboarding). */
    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization->id, 'organizations.read');
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization->id, 'organizations.manage');
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization->id, 'members.manage');
    }

    public function manageInvitations(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization->id, 'organizations.invite');
    }
}
