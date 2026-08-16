<?php

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

function makeMember(User $user, Organization $organization, string $roleKey): Membership
{
    return Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role_id' => Role::where('key', $roleKey)->firstOrFail()->id,
        'status' => 'active',
    ]);
}

it('searches domain availability with prices', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    makeMember($user, $organization, 'owner');

    Sanctum::actingAs($user);

    $response = $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/domains/search?query=omnex&tlds[]=com&tlds[]=io');

    $response->assertOk();

    $items = $response->json('data');
    expect($items)->toHaveCount(2);
    expect($items[0])->toHaveKeys(['domain', 'tld', 'available', 'premium', 'price']);
    expect($items[0]['domain'])->toBe('omnex.com');
});

it('registers a domain and provisions a zone with NS records', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    makeMember($user, $organization, 'owner');

    Sanctum::actingAs($user);

    // "myacme-2026.com" is deterministically available in the sandbox registrar.
    $response = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/domains', ['domain' => 'myacme-2026.com']);

    $response->assertStatus(201)->assertJsonPath('name', 'myacme-2026.com');

    $domain = Domain::withoutTenancy()->where('name', 'myacme-2026.com')->firstOrFail();
    expect($domain->organization_id)->toBe($organization->id);
    expect($domain->zone)->not->toBeNull();
    expect($domain->zone->records()->where('type', 'NS')->count())->toBeGreaterThanOrEqual(2);
});

it('rejects registering an unavailable domain', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    makeMember($user, $organization, 'owner');

    Sanctum::actingAs($user);

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/domains', ['domain' => 'omnex.cloud'])
        ->assertStatus(422);
});

it('renews a domain and extends its expiration', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    makeMember($user, $organization, 'owner');

    $domain = Domain::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'myacme-2026.com',
        'expires_at' => now()->addDays(100),
    ]);

    Sanctum::actingAs($user);

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/renew", ['years' => 2])
        ->assertOk()
        ->assertJsonPath('id', $domain->id);

    expect($domain->fresh()->expires_at->greaterThan(now()->addYears(2)->subDay()))->toBeTrue();
});

it('prevents a viewer from registering a domain', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    makeMember($user, $organization, 'viewer');

    Sanctum::actingAs($user);

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/domains', ['domain' => 'myacme-2026.com'])
        ->assertStatus(403);
});

it('isolates domains between tenants', function () {
    $userA = User::factory()->create();
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    makeMember($userA, $orgA, 'owner');

    $domainB = Domain::factory()->create([
        'organization_id' => $orgB->id,
        'name' => 'myacme-2026.com',
    ]);

    Sanctum::actingAs($userA);

    $this->withHeader('X-Organization', $orgA->id)
        ->getJson('/api/v1/domains')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->withHeader('X-Organization', $orgA->id)
        ->getJson("/api/v1/domains/{$domainB->id}")
        ->assertStatus(404);
});
