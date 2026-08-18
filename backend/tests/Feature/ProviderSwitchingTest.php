<?php

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
});

function providerSwitchContext(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role_id' => Role::where('key', 'owner')->firstOrFail()->id,
        'status' => 'active',
    ]);

    Sanctum::actingAs($user);

    return [$user, $organization];
}

it('reports and switches the sites provider per organization', function () {
    [$user, $organization] = providerSwitchContext();

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/sites/provider')
        ->assertOk()
        ->assertJsonPath('data.name', 'sandbox')
        ->assertJsonPath('data.active', true);

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson('/api/v1/sites/provider', ['name' => 'custom'])
        ->assertOk()
        ->assertJsonPath('data.name', 'custom');

    expect(Organization::findOrFail($organization->id)->settings['site_provider'])->toBe('custom');
});

it('reports and switches the cloud provider per organization', function () {
    [$user, $organization] = providerSwitchContext();

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson('/api/v1/cloud/provider', ['name' => 'hetzner'])
        ->assertOk()
        ->assertJsonPath('data.name', 'hetzner');

    expect(Organization::findOrFail($organization->id)->settings['cloud_provider'])->toBe('hetzner');
});

it('reports and switches the domain provider per organization', function () {
    [$user, $organization] = providerSwitchContext();

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson('/api/v1/domains/provider', ['name' => 'namecheap'])
        ->assertOk()
        ->assertJsonPath('data.name', 'namecheap');

    expect(Organization::findOrFail($organization->id)->settings['domain_provider'])->toBe('namecheap');
});

it('reports and switches the DNS provider per organization', function () {
    [$user, $organization] = providerSwitchContext();

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/dns/providers')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'sandbox')
        ->assertJsonPath('data.1.name', 'cloudflare');

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson('/api/v1/dns/provider', ['name' => 'cloudflare'])
        ->assertOk()
        ->assertJsonPath('data.name', 'cloudflare');

    expect(Organization::findOrFail($organization->id)->settings['dns_provider'])->toBe('cloudflare');
});

it('rejects an unknown provider name', function () {
    [$user, $organization] = providerSwitchContext();

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson('/api/v1/sites/provider', ['name' => 'nope'])
        ->assertStatus(422);
});

it('requires the manage permission to switch a provider', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role_id' => Role::where('key', 'viewer')->firstOrFail()->id,
        'status' => 'active',
    ]);

    Sanctum::actingAs($user);

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/cloud/provider')
        ->assertOk();

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson('/api/v1/cloud/provider', ['name' => 'hetzner'])
        ->assertStatus(403);
});
