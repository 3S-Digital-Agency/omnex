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
 * @return array{0: User, 1: Organization, 2: Domain, 3: DnsZone}
 */
function dnssecContext(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role_id' => Role::where('key', 'owner')->firstOrFail()->id,
        'status' => 'active',
    ]);

    $domain = Domain::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'myacme-2026.com',
    ]);

    $zone = DnsZone::factory()->create([
        'organization_id' => $organization->id,
        'domain_id' => $domain->id,
    ]);

    Sanctum::actingAs($user);

    return [$user, $organization, $domain, $zone];
}

it('starts unsigned with no DS records', function () {
    [$user, $organization, $domain, $zone] = dnssecContext();

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/domains/{$domain->id}/dns/dnssec")
        ->assertOk()
        ->assertJson([
            'enabled' => false,
            'status' => 'unsigned',
            'ds_records' => [],
        ]);
});

it('enables DNSSEC and returns a deterministic DS record', function () {
    [$user, $organization, $domain, $zone] = dnssecContext();

    $response = $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/dns/dnssec")
        ->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('status', 'active');

    $ds = $response->json('ds_records');

    expect($ds)->toHaveCount(1);
    expect($ds[0])->toMatchArray([
        'key_tag' => abs(crc32('myacme-2026.com')) % 65536,
        'algorithm' => 13,
        'digest_type' => 2,
        'digest' => strtoupper(hash('sha256', 'omnex:dnssec:myacme-2026.com')),
    ]);

    expect($zone->fresh()->dnssec_enabled)->toBeTrue();
});

it('rejects enabling DNSSEC twice', function () {
    [$user, $organization, $domain, $zone] = dnssecContext();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/dns/dnssec")
        ->assertOk();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/dns/dnssec")
        ->assertStatus(422);
});

it('disables DNSSEC and clears the DS records', function () {
    [$user, $organization, $domain, $zone] = dnssecContext();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/dns/dnssec")
        ->assertOk();

    $this->withHeader('X-Organization', $organization->id)
        ->deleteJson("/api/v1/domains/{$domain->id}/dns/dnssec")
        ->assertOk()
        ->assertJson([
            'enabled' => false,
            'status' => 'unsigned',
            'ds_records' => [],
        ]);

    expect($zone->fresh()->dnssec_enabled)->toBeFalse();
});

it('rejects disabling DNSSEC when it is not enabled', function () {
    [$user, $organization, $domain, $zone] = dnssecContext();

    $this->withHeader('X-Organization', $organization->id)
        ->deleteJson("/api/v1/domains/{$domain->id}/dns/dnssec")
        ->assertStatus(422);
});

it('prevents a viewer from managing DNSSEC', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role_id' => Role::where('key', 'viewer')->firstOrFail()->id,
        'status' => 'active',
    ]);

    $domain = Domain::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'myacme-2026.com',
    ]);
    DnsZone::factory()->create([
        'organization_id' => $organization->id,
        'domain_id' => $domain->id,
    ]);

    Sanctum::actingAs($user);

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/dns/dnssec")
        ->assertStatus(403);
});

it('isolates DNSSEC between tenants', function () {
    $userA = User::factory()->create();
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    Membership::create([
        'organization_id' => $orgA->id,
        'user_id' => $userA->id,
        'role_id' => Role::where('key', 'owner')->firstOrFail()->id,
        'status' => 'active',
    ]);

    $domainB = Domain::factory()->create([
        'organization_id' => $orgB->id,
        'name' => 'myacme-2026.com',
    ]);
    DnsZone::factory()->create([
        'organization_id' => $orgB->id,
        'domain_id' => $domainB->id,
    ]);

    Sanctum::actingAs($userA);

    $this->withHeader('X-Organization', $orgA->id)
        ->getJson("/api/v1/domains/{$domainB->id}/dns/dnssec")
        ->assertStatus(404);
});
