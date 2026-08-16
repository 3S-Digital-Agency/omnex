<?php

use App\Models\DnsRecord;
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
function propagationContext(): array
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
        'name' => 'propcheck-2026.com',
        'nameservers' => ['ns1.omnex.io', 'ns2.omnex.io'],
    ]);

    $zone = DnsZone::factory()->create([
        'organization_id' => $organization->id,
        'domain_id' => $domain->id,
    ]);

    Sanctum::actingAs($user);

    return [$user, $organization, $domain, $zone];
}

it('returns no checks before any check has run', function () {
    [$user, $organization, $domain, $zone] = propagationContext();

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/domains/{$domain->id}/dns/propagation")
        ->assertOk()
        ->assertJsonPath('summary.total', 0)
        ->assertJsonCount(0, 'data');
});

it('runs a per-nameserver check and reports statuses', function () {
    [$user, $organization, $domain, $zone] = propagationContext();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/dns/records", [
            'type' => 'A',
            'name' => 'app',
            'content' => '203.0.113.5',
        ])->assertStatus(201);

    $response = $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/dns/propagation/check")
        ->assertOk();

    expect($response->json('nameservers'))->toBe(['ns1.omnex.io', 'ns2.omnex.io']);
    expect($response->json('summary.total'))->toBe(2);
    expect($response->json('data'))->toHaveCount(2);

    $nameservers = collect($response->json('data'))->pluck('nameserver')->unique()->sort()->values()->all();
    expect($nameservers)->toBe(['ns1.omnex.io', 'ns2.omnex.io']);

    $statuses = collect($response->json('data'))->pluck('status')->unique()->all();
    expect(array_diff($statuses, ['synced', 'pending', 'outdated', 'error']))->toBe([]);
});

it('is deterministic across repeated checks', function () {
    [$user, $organization, $domain, $zone] = propagationContext();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/dns/records", [
            'type' => 'A',
            'name' => 'app',
            'content' => '203.0.113.5',
        ])->assertStatus(201);

    $first = $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/dns/propagation/check")
        ->assertOk()
        ->json('data');

    $second = $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/dns/propagation/check")
        ->assertOk()
        ->json('data');

    $key = fn (array $check) => $check['nameserver'].'|'.$check['record_name'].'|'.$check['record_type'];

    expect(collect($first)->keyBy($key)->map->status->all())
        ->toBe(collect($second)->keyBy($key)->map->status->all());
});

it('persists the latest checks per nameserver', function () {
    [$user, $organization, $domain, $zone] = propagationContext();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/dns/records", [
            'type' => 'A',
            'name' => 'app',
            'content' => '203.0.113.5',
        ])->assertStatus(201);

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/dns/propagation/check")
        ->assertOk();

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/domains/{$domain->id}/dns/propagation")
        ->assertOk()
        ->assertJsonPath('summary.total', 2)
        ->assertJsonPath('checked_at', fn ($value) => $value !== null);

    expect(DnsRecord::where('zone_id', $zone->id)->count())->toBe(1);
});

it('prevents a viewer from running a propagation check', function () {
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
        'name' => 'propcheck-2026.com',
        'nameservers' => ['ns1.omnex.io'],
    ]);
    DnsZone::factory()->create([
        'organization_id' => $organization->id,
        'domain_id' => $domain->id,
    ]);

    Sanctum::actingAs($user);

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/domains/{$domain->id}/dns/propagation")
        ->assertOk();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/dns/propagation/check")
        ->assertStatus(403);
});

it('isolates propagation between tenants', function () {
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
        'name' => 'propcheck-2026.com',
    ]);
    DnsZone::factory()->create([
        'organization_id' => $orgB->id,
        'domain_id' => $domainB->id,
    ]);

    Sanctum::actingAs($userA);

    $this->withHeader('X-Organization', $orgA->id)
        ->getJson("/api/v1/domains/{$domainB->id}/dns/propagation")
        ->assertStatus(404);
});
