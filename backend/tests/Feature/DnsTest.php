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
function dnsContext(): array
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

it('creates a valid DNS record', function () {
    [$user, $organization, $domain, $zone] = dnsContext();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/dns/records", [
            'type' => 'A',
            'name' => 'app',
            'content' => '203.0.113.5',
            'ttl' => 300,
        ])
        ->assertStatus(201)
        ->assertJsonPath('type', 'A')
        ->assertJsonPath('content', '203.0.113.5');
});

it('rejects an invalid DNS record', function () {
    [$user, $organization, $domain, $zone] = dnsContext();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/dns/records", [
            'type' => 'A',
            'name' => 'app',
            'content' => 'not-an-ip',
        ])
        ->assertStatus(422);
});

it('updates and deletes a DNS record', function () {
    [$user, $organization, $domain, $zone] = dnsContext();

    $created = $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/dns/records", [
            'type' => 'A',
            'name' => 'app',
            'content' => '203.0.113.5',
        ])->assertStatus(201);

    $recordId = $created->json('id');

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson("/api/v1/domains/{$domain->id}/dns/records/{$recordId}", [
            'type' => 'A',
            'name' => 'app',
            'content' => '203.0.113.9',
        ])->assertOk()->assertJsonPath('content', '203.0.113.9');

    $this->withHeader('X-Organization', $organization->id)
        ->deleteJson("/api/v1/domains/{$domain->id}/dns/records/{$recordId}")
        ->assertOk();

    expect(DnsRecord::find($recordId))->toBeNull();
});

it('rolls back an update to its previous content', function () {
    [$user, $organization, $domain, $zone] = dnsContext();

    $created = $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/dns/records", [
            'type' => 'A',
            'name' => 'app',
            'content' => '203.0.113.5',
        ])->assertStatus(201);

    $recordId = $created->json('id');

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson("/api/v1/domains/{$domain->id}/dns/records/{$recordId}", [
            'type' => 'A',
            'name' => 'app',
            'content' => '203.0.113.9',
        ])->assertOk();

    $history = $zone->history()->where('action', 'updated')->latest('created_at')->firstOrFail();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/dns/history/{$history->id}/rollback")
        ->assertOk();

    expect(DnsRecord::find($recordId)->content)->toBe('203.0.113.5');
});

it('exports the zone as a zone file', function () {
    [$user, $organization, $domain, $zone] = dnsContext();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/dns/records", [
            'type' => 'A',
            'name' => '@',
            'content' => '192.0.2.10',
        ])->assertStatus(201);

    $response = $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/domains/{$domain->id}/dns/export");

    $response->assertOk();
    expect($response->json('zone_file'))->toContain('myacme-2026.com');
});

it('imports a zone file and replaces the records', function () {
    [$user, $organization, $domain, $zone] = dnsContext();

    $zoneFile = implode("\n", [
        '$ORIGIN myacme-2026.com.',
        '$TTL 3600',
        '@   IN  A   192.0.2.55',
        'www IN  CNAME @',
    ]);

    $response = $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/dns/import", ['zone_file' => $zoneFile]);

    $response->assertOk();

    $types = collect($response->json('data'))->pluck('type');
    expect($types)->toContain('A')->toContain('CNAME');
});

it('applies a DNS template', function () {
    [$user, $organization, $domain, $zone] = dnsContext();

    $response = $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/dns/templates/website");

    $response->assertStatus(201);

    $types = collect($response->json('data'))->pluck('type');
    expect($types)->toContain('A')->toContain('CNAME');
});

it('isolates DNS between tenants', function () {
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
        ->getJson("/api/v1/domains/{$domainB->id}/dns")
        ->assertStatus(404);
});
