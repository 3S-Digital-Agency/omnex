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

it('creates an organization and assigns the owner role', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/organizations', ['name' => 'Acme Inc']);

    $response->assertStatus(201)->assertJsonPath('name', 'Acme Inc');

    $membership = $user->allMemberships()->first();
    expect($membership)->not->toBeNull();
    expect($membership->role->key)->toBe('owner');
});

it('provisions a new organization with a fully configured environment', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/organizations', ['name' => 'Provisioned Inc']);

    $response->assertStatus(201);

    $organization = Organization::findOrFail($response->json('id'));

    // Every provider brick is assigned (sandbox default) and perks start from
    // plan-tier defaults with no overrides — the tenant is immediately usable.
    expect($organization->settings)->toHaveKeys([
        'storage_provider', 'site_provider', 'cloud_provider',
        'domain_provider', 'dns_provider', 'ssl_provider', 'features',
    ]);
    expect($organization->settings['features'])->toBe([]);
});

it('lists only the organizations the user belongs to', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $ownerRole = Role::where('key', 'owner')->first();

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    Membership::create([
        'organization_id' => $orgA->id,
        'user_id' => $userA->id,
        'role_id' => $ownerRole->id,
        'status' => 'active',
    ]);
    Membership::create([
        'organization_id' => $orgB->id,
        'user_id' => $userB->id,
        'role_id' => $ownerRole->id,
        'status' => 'active',
    ]);

    Sanctum::actingAs($userA);

    $response = $this->getJson('/api/v1/organizations');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('organization.id');
    expect($ids)->toContain($orgA->id)->not->toContain($orgB->id);
});

it('assigns a role to a member', function () {
    $user = User::factory()->create();
    $member = User::factory()->create();
    $ownerRole = Role::where('key', 'owner')->first();
    $viewerRole = Role::where('key', 'viewer')->first();

    $organization = Organization::factory()->create();

    $ownerMembership = Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role_id' => $ownerRole->id,
        'status' => 'active',
    ]);
    $memberMembership = Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $member->id,
        'role_id' => $viewerRole->id,
        'status' => 'active',
    ]);

    Sanctum::actingAs($user);

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson("/api/v1/organizations/{$organization->id}/members/{$memberMembership->id}/role", [
            'role_id' => $ownerRole->id,
        ])->assertOk()->assertJsonPath('role.key', 'owner');
});
