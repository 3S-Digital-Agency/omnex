<?php

use App\Models\DnsZone;
use App\Models\Domain;
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

/**
 * @return array{0: User, 1: Organization}
 */
function securityContext(): array
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

it('computes the security score from open findings', function () {
    [$user, $organization] = securityContext();

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/security')
        ->assertOk()
        ->assertJsonPath('score', 60)
        ->assertJsonPath('summary.open', 2)
        ->assertJsonPath('summary.high', 1)
        ->assertJsonPath('summary.medium', 1);
});

it('flags an unverified email address', function () {
    $user = User::factory()->unverified()->create();
    $organization = Organization::factory()->create();

    Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role_id' => Role::where('key', 'owner')->firstOrFail()->id,
        'status' => 'active',
    ]);

    Sanctum::actingAs($user);

    $response = $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/security')
        ->assertOk()
        ->assertJsonPath('score', 50);

    $rules = collect($response->json('findings'))->pluck('rule');

    expect($rules)->toContain('email');
});

it('flags expiring domains and unsigned zones', function () {
    [$user, $organization] = securityContext();

    $domain = Domain::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'expiring-2026.com',
        'expires_at' => now()->addDays(5),
    ]);

    DnsZone::factory()->create([
        'organization_id' => $organization->id,
        'domain_id' => $domain->id,
        'dnssec_enabled' => false,
    ]);

    $response = $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/security')
        ->assertOk()
        ->assertJsonPath('score', 35);

    $rules = collect($response->json('findings'))->pluck('rule');

    expect($rules)->toContain('domain_expiring')->toContain('dnssec_disabled');
});

it('resolves findings that are fixed on rescan', function () {
    $user = User::factory()->unverified()->create();
    $organization = Organization::factory()->create();

    Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role_id' => Role::where('key', 'owner')->firstOrFail()->id,
        'status' => 'active',
    ]);

    Sanctum::actingAs($user);

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/security')
        ->assertJsonPath('summary.open', 3);

    $user->update(['mfa_enabled' => true, 'email_verified_at' => now()]);

    Membership::create([
        'organization_id' => $organization->id,
        'user_id' => User::factory()->create(['mfa_enabled' => true])->id,
        'role_id' => Role::where('key', 'developer')->firstOrFail()->id,
        'status' => 'active',
    ]);

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/security/scan')
        ->assertOk()
        ->assertJsonPath('score', 100)
        ->assertJsonPath('summary.open', 0)
        ->assertJsonPath('summary.resolved', 3);
});

it('dismisses and reopens a finding', function () {
    [$user, $organization] = securityContext();

    $findings = $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/security')
        ->json('findings');

    $mfa = collect($findings)->firstWhere('rule', 'mfa');

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/security/findings/{$mfa['id']}/dismiss")
        ->assertOk()
        ->assertJsonPath('status', 'dismissed');

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/security')
        ->assertOk()
        ->assertJsonPath('score', 85)
        ->assertJsonPath('summary.open', 1)
        ->assertJsonPath('summary.dismissed', 1);

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/security/findings/{$mfa['id']}/reopen")
        ->assertOk()
        ->assertJsonPath('status', 'open');

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/security')
        ->assertJsonPath('score', 60)
        ->assertJsonPath('summary.open', 2);
});

it('enforces security permissions', function () {
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
        ->getJson('/api/v1/security')
        ->assertOk();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/security/scan')
        ->assertStatus(403);
});

it('isolates findings between tenants', function () {
    $userA = User::factory()->create();
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    Membership::create([
        'organization_id' => $orgA->id,
        'user_id' => $userA->id,
        'role_id' => Role::where('key', 'owner')->firstOrFail()->id,
        'status' => 'active',
    ]);

    $userB = User::factory()->create();
    Membership::create([
        'organization_id' => $orgB->id,
        'user_id' => $userB->id,
        'role_id' => Role::where('key', 'owner')->firstOrFail()->id,
        'status' => 'active',
    ]);

    Sanctum::actingAs($userB);

    $finding = $this->withHeader('X-Organization', $orgB->id)
        ->getJson('/api/v1/security')
        ->json('findings.0');

    Sanctum::actingAs($userA);

    $this->withHeader('X-Organization', $orgA->id)
        ->postJson("/api/v1/security/findings/{$finding['id']}/dismiss")
        ->assertStatus(404);
});
