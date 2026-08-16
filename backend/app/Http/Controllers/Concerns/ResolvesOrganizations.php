<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

trait ResolvesOrganizations
{
    /**
     * Resolve an organization for a scoped route, verifying the caller is a
     * member AND that it matches the active tenant context.
     */
    protected function resolveScopedOrganization(Request $request, string $id): Organization
    {
        $organization = Organization::findOrFail($id);

        $this->authorize('view', $organization);

        abort_unless(
            app(TenantContext::class)->id() === $organization->id,
            403,
            'Organization context mismatch.'
        );

        return $organization;
    }
}
