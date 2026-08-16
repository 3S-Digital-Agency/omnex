<?php

namespace App\Support\Tenancy;

use App\Models\Organization;

/**
 * Holds the active organization (tenant) for the current request.
 * Resolved by ResolveTenant middleware from the authenticated user and the
 * X-Organization header. Everything tenant-scoped reads from here.
 */
final class TenantContext
{
    private ?string $organizationId = null;

    private ?Organization $organization = null;

    public function set(?string $organizationId, ?Organization $organization = null): void
    {
        $this->organizationId = $organizationId;
        $this->organization = $organization;
    }

    public function id(): ?string
    {
        return $this->organizationId;
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function hasTenant(): bool
    {
        return $this->organizationId !== null;
    }
}
