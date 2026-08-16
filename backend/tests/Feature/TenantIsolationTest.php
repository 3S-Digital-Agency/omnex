<?php

use App\Models\AuditLog;
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

function makeOwner(User $user, Organization $organization, Role $role): Membership
{
    return Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role_id' => $role->id,
        'status' => 'active',
    ]);
}

it('prevents a user from listing another organization members', function () {
    $ownerRole = Role::where('key', 'owner')->first();

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    makeOwner($userA, $orgA, $ownerRole);
    makeOwner($userB, $orgB, $ownerRole);

    Sanctum::actingAs($userA);

    $this->withHeader('X-Organization', $orgA->id)
        ->getJson("/api/v1/organizations/{$orgB->id}/members")
        ->assertStatus(403);
});

it('scopes member listings to the active organization', function () {
    $ownerRole = Role::where('key', 'owner')->first();

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    makeOwner($userA, $orgA, $ownerRole);
    makeOwner($userB, $orgB, $ownerRole);

    Sanctum::actingAs($userA);

    $response = $this->withHeader('X-Organization', $orgA->id)
        ->getJson("/api/v1/organizations/{$orgA->id}/members");

    $response->assertOk();
    $userIds = collect($response->json('data'))->pluck('user.id');
    expect($userIds)->toContain($userA->id)->not->toContain($userB->id);
});

it('scopes audit logs to the active organization', function () {
    $ownerRole = Role::where('key', 'owner')->first();

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    makeOwner($userA, $orgA, $ownerRole);
    makeOwner($userB, $orgB, $ownerRole);

    AuditLog::create([
        'organization_id' => $orgA->id,
        'user_id' => $userA->id,
        'action' => 'test.secret_a',
        'result' => 'success',
    ]);
    AuditLog::create([
        'organization_id' => $orgB->id,
        'user_id' => $userB->id,
        'action' => 'test.secret_b',
        'result' => 'success',
    ]);

    Sanctum::actingAs($userA);

    $response = $this->withHeader('X-Organization', $orgA->id)->getJson('/api/v1/audit');

    $response->assertOk();
    $actions = collect($response->json('data'))->pluck('action');
    expect($actions)->toContain('test.secret_a')->not->toContain('test.secret_b');
});

it('rejects an unknown organization header', function () {
    $ownerRole = Role::where('key', 'owner')->first();

    $orgA = Organization::factory()->create();
    $userA = User::factory()->create();
    makeOwner($userA, $orgA, $ownerRole);

    Sanctum::actingAs($userA);

    // A UUID the user is not a member of must be rejected, not silently ignored.
    $this->withHeader('X-Organization', Organization::factory()->create()->id)
        ->getJson('/api/v1/me')
        ->assertStatus(403);
});
