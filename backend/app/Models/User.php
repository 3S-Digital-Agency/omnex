<?php

namespace App\Models;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'email',
        'password',
        'mfa_secret',
        'mfa_enabled',
        'recovery_codes',
        'email_verified_at',
        'locale',
        'status',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret',
        'recovery_codes',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'password' => 'hashed',
        'mfa_enabled' => 'boolean',
        'mfa_secret' => 'encrypted',
        'recovery_codes' => 'encrypted:array',
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * The tenant scope does not apply here: a user's membership list spans
     * organizations by design (org switcher, context resolution).
     */
    public function allMemberships(): HasMany
    {
        return $this->memberships()->withoutTenancy();
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'memberships')
            ->withPivot('role_id', 'status')
            ->withTimestamps();
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function activeMembership(): ?Membership
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId === null) {
            return null;
        }

        return $this->allMemberships()
            ->where('organization_id', $tenantId)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Check a permission in a *specific* organization (used by policies), as
     * opposed to hasPermission() which uses the active request context.
     */
    public function hasOrganizationPermission(?string $organizationId, string $permission): bool
    {
        if ($organizationId === null) {
            return false;
        }

        $membership = $this->allMemberships()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->first();

        return $membership?->role?->permissions()
            ->where('key', $permission)
            ->exists() ?? false;
    }

    private ?array $cachedPermissionKeys = null;

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissionKeys(), true);
    }

    public function permissionKeys(): array
    {
        if ($this->cachedPermissionKeys !== null) {
            return $this->cachedPermissionKeys;
        }

        $membership = $this->activeMembership();

        return $this->cachedPermissionKeys = $membership?->role?->permissions()
            ->pluck('key')
            ->all() ?? [];
    }
}
