<?php

namespace App\Support\Organizations;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Single writer for organization creation. Provisioning a tenant is atomic
 * and immediately functional: the owning membership is created together with
 * an explicit per-organization environment (every provider brick assigned to
 * its sandbox default — real providers activate once their tokens are set)
 * and an empty feature-override map (plan-tier perks apply). No tenant is
 * left in a half-configured state.
 */
final class OrganizationService
{
    public function create(User $owner, string $name): Organization
    {
        return DB::transaction(function () use ($owner, $name) {
            $organization = Organization::create([
                'name' => trim($name),
                'plan_tier' => 'free',
                'status' => 'active',
                'settings' => $this->defaultSettings(),
            ]);

            Membership::create([
                'organization_id' => $organization->id,
                'user_id' => $owner->id,
                'role_id' => Role::where('key', 'owner')->firstOrFail()->id,
                'status' => 'active',
                'joined_at' => now(),
            ]);

            AuditLogger::record('organization.provisioned', 'organization', $organization->id, null, [
                'name' => $organization->name,
                'providers' => collect($this->defaultSettings())
                    ->except(['features'])
                    ->all(),
            ]);

            return $organization;
        });
    }

    /**
     * The environment every fresh organization starts with. Provider names
     * (not credentials) live here so the tenant is self-describing and
     * portable; credentials stay in server-side config/secrets.
     *
     * @return array<string, mixed>
     */
    private function defaultSettings(): array
    {
        return [
            'storage_provider' => config('omnex.storage.provider', 'sandbox'),
            'site_provider' => config('omnex.sites.provider', 'sandbox'),
            'cloud_provider' => config('omnex.cloud.provider', 'sandbox'),
            'domain_provider' => config('omnex.domain.provider', 'sandbox'),
            'dns_provider' => config('omnex.domain.dns_provider', 'sandbox'),
            'ssl_provider' => config('omnex.ssl.provider', 'sandbox'),
            'features' => [],
        ];
    }
}
