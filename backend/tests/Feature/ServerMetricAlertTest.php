<?php

use App\Models\Membership;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\Support\Cloud\ServerService;
use App\Support\Tenancy\TenantContext;
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
function alertContext(string $role = 'owner'): array
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

it('fires a server.alert notification when a metric sample crosses the threshold', function () {
    [$user, $organization] = alertContext();

    config()->set('omnex.cloud.alerts', ['cpu' => 5, 'memory' => 90, 'disk' => 90, 'cooldown_seconds' => 3600]);
    config()->set('omnex.cloud.metrics_sse_max_seconds', 0);

    $server = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'alert-01',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])->assertStatus(201)->json('id');

    $stream = $this->withHeader('X-Organization', $organization->id)
        ->get("/api/v1/cloud/{$server}/metrics/stream");

    ob_start();
    $stream->baseResponse->sendContent();
    ob_get_clean();

    $notification = Notification::withoutTenancy()
        ->where('organization_id', $organization->id)
        ->where('type', 'server.alert')
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification->user_id)->toBe($user->id)
        ->and($notification->severity)->toBe('warning')
        ->and($notification->data['route'])->toBe('/cloud')
        ->and($notification->data['server_id'])->toBe($server)
        ->and($notification->body)->toContain('cpu');
});

it('sends the alert to every member with cloud.read', function () {
    [$user, $organization] = alertContext();

    $developer = User::factory()->create();
    Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $developer->id,
        'role_id' => Role::where('key', 'developer')->firstOrFail()->id,
        'status' => 'active',
    ]);

    $viewer = User::factory()->create();
    Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $viewer->id,
        'role_id' => Role::where('key', 'viewer')->firstOrFail()->id,
        'status' => 'active',
    ]);

    $server = Server::create([
        'organization_id' => $organization->id,
        'name' => 'alert-02',
        'region' => 'fsn1',
        'plan' => 'cpx11',
        'image' => 'ubuntu-24.04',
        'provider' => 'sandbox',
        'provider_server_id' => 'sbox-srv-alert-02',
        'status' => 'running',
    ]);

    app(TenantContext::class)->set($organization->id, $organization);

    app(ServerService::class)->checkMetricsThresholds($server, [
        'cpu' => 97,
        'memory_used' => 3 * 1024 * 1024 * 1024,
        'memory_total' => 4 * 1024 * 1024 * 1024,
        'disk_used' => 70 * 1024 * 1024 * 1024,
        'disk_total' => 80 * 1024 * 1024 * 1024,
    ]);

    $recipients = Notification::withoutTenancy()
        ->where('organization_id', $organization->id)
        ->where('type', 'server.alert')
        ->pluck('user_id')
        ->sort()
        ->values()
        ->all();

    expect($recipients)->toBe(collect([$user->id, $developer->id, $viewer->id])->sort()->values()->all());
});

it('honors the per-metric cooldown and raises no duplicate alerts', function () {
    [$user, $organization] = alertContext();

    config()->set('omnex.cloud.alerts', ['cpu' => 5, 'memory' => 90, 'disk' => 90, 'cooldown_seconds' => 3600]);

    $server = Server::create([
        'organization_id' => $organization->id,
        'name' => 'alert-03',
        'region' => 'fsn1',
        'plan' => 'cpx11',
        'image' => 'ubuntu-24.04',
        'provider' => 'sandbox',
        'provider_server_id' => 'sbox-srv-alert-03',
        'status' => 'running',
    ]);

    app(TenantContext::class)->set($organization->id, $organization);

    $metrics = [
        'cpu' => 97,
        'memory_used' => 3 * 1024 * 1024 * 1024,
        'memory_total' => 4 * 1024 * 1024 * 1024,
        'disk_used' => 70 * 1024 * 1024 * 1024,
        'disk_total' => 80 * 1024 * 1024 * 1024,
    ];

    app(ServerService::class)->checkMetricsThresholds($server, $metrics);
    app(ServerService::class)->checkMetricsThresholds($server, $metrics);

    expect(Notification::withoutTenancy()->where('type', 'server.alert')->count())->toBe(1);

    // After the cooldown elapses a new breach fires again.
    $this->travel(2)->hours();

    app(ServerService::class)->checkMetricsThresholds($server, $metrics);

    expect(Notification::withoutTenancy()->where('type', 'server.alert')->count())->toBe(2);

    $this->travelBack();
});

it('does not raise an alert while usage stays under the threshold', function () {
    [$user, $organization] = alertContext();

    config()->set('omnex.cloud.alerts', ['cpu' => 90, 'memory' => 90, 'disk' => 90, 'cooldown_seconds' => 3600]);

    $server = Server::create([
        'organization_id' => $organization->id,
        'name' => 'alert-04',
        'region' => 'fsn1',
        'plan' => 'cpx11',
        'image' => 'ubuntu-24.04',
        'provider' => 'sandbox',
        'provider_server_id' => 'sbox-srv-alert-04',
        'status' => 'running',
    ]);

    app(TenantContext::class)->set($organization->id, $organization);

    app(ServerService::class)->checkMetricsThresholds($server, [
        'cpu' => 42,
        'memory_used' => 1 * 1024 * 1024 * 1024,
        'memory_total' => 4 * 1024 * 1024 * 1024,
        'disk_used' => 20 * 1024 * 1024 * 1024,
        'disk_total' => 80 * 1024 * 1024 * 1024,
    ]);

    expect(Notification::withoutTenancy()->where('type', 'server.alert')->count())->toBe(0);
});
