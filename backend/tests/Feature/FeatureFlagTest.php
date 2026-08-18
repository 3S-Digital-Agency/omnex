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

function featureContext(string $tier = 'free', string $role = 'owner'): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create(['plan_tier' => $tier]);

    Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role_id' => Role::where('key', $role)->firstOrFail()->id,
        'status' => 'active',
    ]);

    Sanctum::actingAs($user);

    return [$user, $organization];
}

it('resolves plan-tier defaults for a free organization', function () {
    [$user, $organization] = featureContext('free');

    $flags = collect($this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/features')
        ->assertOk()
        ->json('data'));

    expect($flags->firstWhere('key', 'domains')['enabled'])->toBeTrue();
    expect($flags->firstWhere('key', 'drive')['enabled'])->toBeTrue();
    expect($flags->firstWhere('key', 'cloud')['enabled'])->toBeFalse();
    expect($flags->firstWhere('key', 'ssl')['enabled'])->toBeFalse();
    expect($flags->firstWhere('key', 'real_providers')['enabled'])->toBeFalse();
    expect($flags->firstWhere('key', 'domain_limit')['value'])->toBe(1);
    expect($flags->firstWhere('key', 'storage_quota_bytes')['value'])->toBe(1073741824);
});

it('resolves plan-tier defaults for a pro organization', function () {
    [$user, $organization] = featureContext('pro');

    $flags = collect($this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/features')
        ->json('data'));

    expect($flags->firstWhere('key', 'cloud')['enabled'])->toBeTrue();
    expect($flags->firstWhere('key', 'ssl')['enabled'])->toBeTrue();
    expect($flags->firstWhere('key', 'real_providers')['enabled'])->toBeTrue();
    expect($flags->firstWhere('key', 'domain_limit')['value'])->toBe(0); // unlimited
});

it('applies and resets an organization override', function () {
    [$user, $organization] = featureContext('free');

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson('/api/v1/features/ssl', ['value' => true])
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.source', 'override');

    expect(Organization::findOrFail($organization->id)->settings['features']['ssl'])->toBeTrue();

    $this->withHeader('X-Organization', $organization->id)
        ->deleteJson('/api/v1/features/ssl/override')
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.source', 'plan');
});

it('rejects an unknown feature flag and coerces numeric values', function () {
    [$user, $organization] = featureContext('pro');

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson('/api/v1/features/nope', ['value' => true])
        ->assertStatus(422);

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson('/api/v1/features/domain_limit', ['value' => '42'])
        ->assertOk()
        ->assertJsonPath('data.value', 42);
});

it('requires organizations.manage to override a flag', function () {
    [$user, $organization] = featureContext('pro', 'viewer');

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/features')
        ->assertOk();

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson('/api/v1/features/ssl', ['value' => false])
        ->assertStatus(403);
});
