<?php

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerSnapshot;
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
function snapshotContext(string $role = 'owner'): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role_id' => Role::where('key', $role)->firstOrFail()->id,
        'status' => 'active',
    ]);

    Sanctum::actingAs($user);

    return [$user, $organization];
}

/**
 * @return array{0: User, 1: Organization, 2: string}
 */
function snapshotServer($test, string $role = 'owner'): array
{
    [$user, $organization] = snapshotContext($role);

    $server = $test->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'backup-01',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])->assertStatus(201)->json('id');

    return [$user, $organization, $server];
}

it('creates, lists and deletes a snapshot', function () {
    [$user, $organization, $server] = snapshotServer($this);

    $created = $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/cloud/{$server}/snapshots", ['label' => 'pre-deploy'])
        ->assertStatus(201)
        ->assertJsonPath('label', 'pre-deploy')
        ->assertJsonPath('status', 'available');

    $snapshotId = $created->json('id');

    expect($created->json('provider_snapshot_id'))->toStartWith('sbox-snap-');

    // A snapshot operation is recorded in the trail. (provision + snapshot can
    // share the same created_at second, so find the op by type instead of
    // assuming the first row.)
    $operations = $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/cloud/{$server}/operations")
        ->assertOk()
        ->json('data');

    $snapshotOp = collect($operations)->first(fn ($op) => $op['type'] === 'snapshot');

    expect($snapshotOp)->not->toBeNull()
        ->and($snapshotOp['status'])->toBe('succeeded');

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/cloud/{$server}/snapshots")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $snapshotId);

    $this->withHeader('X-Organization', $organization->id)
        ->deleteJson("/api/v1/cloud/{$server}/snapshots/{$snapshotId}")
        ->assertStatus(204);

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/cloud/{$server}/snapshots")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('fails snapshot creation deterministically on servers named fail-*', function () {
    [$user, $organization] = snapshotContext();

    $server = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'fail-box',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])->assertStatus(201)->json('id');

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/cloud/{$server}/snapshots")
        ->assertStatus(503);

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/cloud/{$server}/operations")
        ->assertOk()
        ->assertJsonPath('data.0.type', 'snapshot')
        ->assertJsonPath('data.0.status', 'failed');
});

it('updates snapshot frequency and retention settings', function () {
    [$user, $organization, $server] = snapshotServer($this);

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson("/api/v1/cloud/{$server}", [
            'snapshot_frequency' => 'daily',
            'snapshot_retention_days' => 14,
        ])
        ->assertOk()
        ->assertJsonPath('snapshot_frequency', 'daily')
        ->assertJsonPath('snapshot_retention_days', 14);

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson("/api/v1/cloud/{$server}", [
            'snapshot_frequency' => 'hourly',
        ])
        ->assertStatus(422);

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson("/api/v1/cloud/{$server}", [
            'snapshot_retention_days' => 0,
        ])
        ->assertStatus(422);
});

it('creates snapshots for due servers and enforces retention via the scheduler', function () {
    [$user, $organization, $serverId] = snapshotServer($this);

    $server = Server::withoutTenancy()->find($serverId);

    $server->update([
        'snapshot_frequency' => 'daily',
        'snapshot_retention_days' => 2,
    ]);

    // The server has never been snapshotted → due immediately.
    $this->artisan('omnex:server-snapshots')
        ->expectsOutputToContain('Created 1 snapshot(s)')
        ->assertExitCode(0);

    expect(ServerSnapshot::withoutTenancy()->where('server_id', $serverId)->count())->toBe(1);

    // Fresh snapshot → not due again today.
    $this->artisan('omnex:server-snapshots')
        ->expectsOutputToContain('Created 0 snapshot(s)')
        ->assertExitCode(0);

    expect(ServerSnapshot::withoutTenancy()->where('server_id', $serverId)->count())->toBe(1);

    // Two days later the schedule fires again and the old snapshot expires.
    $this->travel(3)->days();

    // Direct kernel call so we can assert on the raw output (the mocked
    // console used by `artisan()->expectsOutputToContain()` is unreliable
    // when a single write contains several expected substrings).
    $exitCode = Artisan::call('omnex:server-snapshots');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Created 1 snapshot(s)')
        ->and($output)->toContain('pruned 1 expired snapshot(s)');

    $this->travelBack();

    expect(ServerSnapshot::withoutTenancy()->where('server_id', $serverId)->count())->toBe(1)
        ->and(ServerSnapshot::withoutTenancy()->where('server_id', $serverId)->first()->label)->toStartWith('snapshot-');
});

it('does not snapshot servers with snapshots disabled', function () {
    [$user, $organization, $serverId] = snapshotServer($this);

    $this->artisan('omnex:server-snapshots')->assertExitCode(0);

    expect(ServerSnapshot::withoutTenancy()->where('server_id', $serverId)->count())->toBe(0);
});

it('dry-runs the scheduler without writing anything', function () {
    [$user, $organization, $serverId] = snapshotServer($this);

    Server::withoutTenancy()->find($serverId)->update(['snapshot_frequency' => 'weekly']);

    $this->artisan('omnex:server-snapshots', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run: 1 snapshot(s) due')
        ->assertExitCode(0);

    expect(ServerSnapshot::withoutTenancy()->where('server_id', $serverId)->count())->toBe(0);
});

it('requires cloud.manage to create or delete snapshots', function () {
    // Owner provisions the server.
    [$owner, $organization] = snapshotContext('owner');

    $server = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'read-only-01',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])->assertStatus(201)->json('id');

    // A viewer member: can list snapshots (cloud.read)…
    $viewer = User::factory()->create();
    Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $viewer->id,
        'role_id' => Role::where('key', 'viewer')->firstOrFail()->id,
        'status' => 'active',
    ]);

    Sanctum::actingAs($viewer);

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/cloud/{$server}/snapshots")
        ->assertOk();

    // …but cannot create them.
    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/cloud/{$server}/snapshots")
        ->assertStatus(403);
});

it('isolates snapshots between tenants', function () {
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

    $server = $this->withHeader('X-Organization', $orgB->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'private-snap',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])->assertStatus(201)->json('id');

    $snapshot = $this->withHeader('X-Organization', $orgB->id)
        ->postJson("/api/v1/cloud/{$server}/snapshots")
        ->assertStatus(201)->json('id');

    Sanctum::actingAs($userA);

    $this->withHeader('X-Organization', $orgA->id)
        ->getJson("/api/v1/cloud/{$server}/snapshots")
        ->assertStatus(404);

    $this->withHeader('X-Organization', $orgA->id)
        ->deleteJson("/api/v1/cloud/{$server}/snapshots/{$snapshot}")
        ->assertStatus(404);
});
