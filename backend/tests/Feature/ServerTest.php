<?php

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerMetricSample;
use App\Models\ServerOperation;
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
function cloudContext(): array
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

it('lists the cloud providers', function () {
    [$user, $organization] = cloudContext();

    $response = $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/cloud/providers')
        ->assertOk();

    expect($response->json('data.0'))->toMatchArray(['name' => 'sandbox', 'configured' => true]);
    expect($response->json('data.1'))->toMatchArray(['name' => 'hetzner', 'configured' => false]);
    expect($response->json('data.2'))->toMatchArray(['name' => 'digitalocean', 'configured' => false]);
    expect($response->json('data.3'))->toMatchArray(['name' => 'custom', 'configured' => false]);
});

it('verifies cloud provider credentials without provisioning', function () {
    [$user, $organization] = cloudContext();

    // Sandbox verifies without any external call; real providers fail with a
    // clear reason when their tokens are not set.
    $response = $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/cloud/providers/verify')
        ->assertOk();

    expect($response->json('data.0.name'))->toBe('sandbox')
        ->and($response->json('data.0.verified.ok'))->toBeTrue();

    $verified = collect($response->json('data'))->keyBy('name');

    expect($verified['hetzner']['verified']['ok'])->toBeFalse()
        ->and($verified['hetzner']['verified']['detail'])->toContain('HETZNER_API_TOKEN')
        ->and($verified['digitalocean']['verified']['ok'])->toBeFalse()
        ->and($verified['digitalocean']['verified']['detail'])->toContain('DO_API_TOKEN');
});

it('verifies a single named provider', function () {
    [$user, $organization] = cloudContext();

    $response = $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/cloud/providers/verify?provider=sandbox')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.name'))->toBe('sandbox')
        ->and($response->json('data.0.verified.ok'))->toBeTrue();
});

it('provisions a server and records the provision operation', function () {
    [$user, $organization] = cloudContext();

    $response = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'web-01',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
            'ssh_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI…',
            'tags' => ['web', 'staging'],
        ])
        ->assertStatus(201)
        ->assertJsonPath('name', 'web-01')
        ->assertJsonPath('status', 'running')
        ->assertJsonPath('provider', 'sandbox')
        ->assertJsonPath('operations_count', 1);

    $serverId = $response->json('id');

    expect($response->json('ipv4'))->toMatch('/^\d+\.\d+\.\d+\.\d+$/');
    expect($response->json('tags'))->toBe(['web', 'staging']);

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/cloud/{$serverId}/operations")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'provision')
        ->assertJsonPath('data.0.status', 'succeeded');
});

it('rejects invalid names, regions, plans and images', function () {
    [$user, $organization] = cloudContext();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'Bad Name!',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])->assertStatus(422);

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'web-01',
            'region' => 'nowhere',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])->assertStatus(422);

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'web-01',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'windows-11',
        ])->assertStatus(422);
});

it('fails provisioning deterministically for the name "fail"', function () {
    [$user, $organization] = cloudContext();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'fail',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])->assertStatus(503);
});

it('runs start, stop and reboot operations and updates the server status', function () {
    [$user, $organization] = cloudContext();

    $server = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'db-01',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])->assertStatus(201)->json('id');

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/cloud/{$server}/stop")
        ->assertStatus(201)
        ->assertJsonPath('type', 'stop')
        ->assertJsonPath('status', 'succeeded');

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/cloud/{$server}")
        ->assertOk()
        ->assertJsonPath('status', 'stopped');

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/cloud/{$server}/start")
        ->assertStatus(201)
        ->assertJsonPath('status', 'succeeded');

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/cloud/{$server}/reboot")
        ->assertStatus(201)
        ->assertJsonPath('status', 'succeeded');

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/cloud/{$server}")
        ->assertOk()
        ->assertJsonPath('status', 'running')
        ->assertJsonPath('operations_count', 4);
});

it('rebuilds a server onto a new image', function () {
    [$user, $organization] = cloudContext();

    $server = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'app-01',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])->assertStatus(201)->json('id');

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/cloud/{$server}/rebuild", ['image' => 'debian-12'])
        ->assertStatus(201)
        ->assertJsonPath('type', 'rebuild')
        ->assertJsonPath('status', 'succeeded');

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/cloud/{$server}")
        ->assertOk()
        ->assertJsonPath('image', 'debian-12')
        ->assertJsonPath('status', 'running');
});

it('records a failed operation and keeps the server status for servers named fail-*', function () {
    [$user, $organization] = cloudContext();

    $server = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'fail-box',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])->assertStatus(201)->json('id');

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/cloud/{$server}/stop")
        ->assertStatus(201)
        ->assertJsonPath('type', 'stop')
        ->assertJsonPath('status', 'failed');

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/cloud/{$server}")
        ->assertOk()
        ->assertJsonPath('status', 'running');
});

it('updates a server name, ssh key and tags', function () {
    [$user, $organization] = cloudContext();

    $server = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'web-01',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])->assertStatus(201)->json('id');

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson("/api/v1/cloud/{$server}", [
            'name' => 'web-02',
            'ssh_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI…',
            'tags' => ['prod'],
        ])
        ->assertOk()
        ->assertJsonPath('name', 'web-02')
        ->assertJsonPath('tags', ['prod']);
});

it('deletes a server and its operations', function () {
    [$user, $organization] = cloudContext();

    $server = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'gone-01',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])->assertStatus(201)->json('id');

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/cloud/{$server}/reboot")
        ->assertStatus(201);

    $this->withHeader('X-Organization', $organization->id)
        ->deleteJson("/api/v1/cloud/{$server}")
        ->assertStatus(204);

    expect(Server::withoutTenancy()->find($server))->toBeNull();
    expect(ServerOperation::withoutTenancy()->where('server_id', $server)->exists())->toBeFalse();
});

it('streams server.metrics samples over SSE', function () {
    [$user, $organization] = cloudContext();

    $server = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'metrics-01',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])->assertStatus(201)->json('id');

    config()->set('omnex.cloud.metrics_sse_max_seconds', 0);

    $response = $this->withHeader('X-Organization', $organization->id)
        ->get("/api/v1/cloud/{$server}/metrics/stream");

    ob_start();
    $response->baseResponse->sendContent();
    $output = ob_get_clean();

    $response->assertStatus(200);

    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache');

    expect($output)->toContain('event: server.metrics')
        ->and($output)->toContain('server_id')
        ->and($output)->toContain('cpu')
        ->and($output)->toContain('memory_total')
        ->and($output)->toContain('disk_total');
});

it('persists metric samples and serves them as history', function () {
    [$user, $organization] = cloudContext();

    $server = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'history-01',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])->assertStatus(201)->json('id');

    config()->set('omnex.cloud.metrics_sse_max_seconds', 0);

    $stream = $this->withHeader('X-Organization', $organization->id)
        ->get("/api/v1/cloud/{$server}/metrics/stream");

    ob_start();
    $stream->baseResponse->sendContent();
    ob_get_clean();

    $history = $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/cloud/{$server}/metrics/history")
        ->assertOk();

    expect($history->json('data'))->toHaveCount(1);
    expect($history->json('data.0.cpu'))->toBeBetween(5, 95);
    expect($history->json('data.0.server_id'))->toBe($server);
    expect($history->json('data.0.memory_total'))->toBe(4 * 1024 * 1024 * 1024);
    expect($history->json('data.0.disk_total'))->toBe(80 * 1024 * 1024 * 1024);
});

it('honors the history limit and keeps the newest samples first', function () {
    [$user, $organization] = cloudContext();

    $server = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'history-limit',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])->assertStatus(201)->json('id');

    config()->set('omnex.cloud.metrics_sse_max_seconds', 0);

    for ($i = 0; $i < 3; $i += 1) {
        $this->withHeader('X-Organization', $organization->id)
            ->get("/api/v1/cloud/{$server}/metrics/stream")
            ->baseResponse->sendContent();
    }

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/cloud/{$server}/metrics/history?limit=2")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect(ServerMetricSample::withoutTenancy()->where('server_id', $server)->count())->toBe(3);
});

it('isolates the metrics history between tenants', function () {
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
            'name' => 'private-history',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])->assertStatus(201)->json('id');

    Sanctum::actingAs($userA);

    $this->withHeader('X-Organization', $orgA->id)
        ->getJson("/api/v1/cloud/{$server}/metrics/history")
        ->assertStatus(404);
});

it('requires cloud.read to stream server metrics', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role_id' => Role::where('key', 'viewer')->firstOrFail()->id,
        'status' => 'active',
    ]);

    // Remove cloud.read: viewer has it by default, so use a bare user with no membership.
    $user->memberships()->delete();

    Sanctum::actingAs($user);

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/cloud/providers')
        ->assertStatus(403);
});

it('isolates the metrics stream between tenants', function () {
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
            'name' => 'private-metrics',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])->assertStatus(201)->json('id');

    Sanctum::actingAs($userA);

    $this->withHeader('X-Organization', $orgA->id)
        ->getJson("/api/v1/cloud/{$server}/metrics/stream")
        ->assertStatus(404);
});

it('enforces cloud permissions', function () {
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
        ->getJson('/api/v1/cloud')
        ->assertOk();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'denied-01',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])
        ->assertStatus(403);
});

it('isolates servers between tenants', function () {
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
            'name' => 'private-01',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])->assertStatus(201)->json('id');

    Sanctum::actingAs($userA);

    $this->withHeader('X-Organization', $orgA->id)
        ->getJson("/api/v1/cloud/{$server}")
        ->assertStatus(404);
});
