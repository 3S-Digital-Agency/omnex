<?php

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Streams\StreamBroker;
use App\Support\Streams\StreamChannels;
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
function activityContext(): array
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
    app(TenantContext::class)->set($organization->id, $organization);

    return [$user, $organization];
}

it('broadcasts an activity event when an audit log is recorded', function () {
    [$user, $organization] = activityContext();

    AuditLogger::record('domain.registered', 'domain', 'dom-1', null, ['name' => 'omnex.dev']);

    $events = [];
    app(StreamBroker::class)->listen(StreamChannels::activity($organization->id), function (array $event) use (&$events) {
        $events[] = $event;
    }, 0);

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('domain')
        ->and($events[0]['severity'])->toBe('success')
        ->and($events[0]['title'])->toBe('Domain registered')
        ->and($events[0]['description'])->toBe('omnex.dev')
        ->and($events[0]['id'])->toBeInt();
});

it('does not broadcast tenant-less audit records (auth events)', function () {
    activityContext();

    // No tenant is set, so organization_id resolves to null.
    app(TenantContext::class)->set(null, null);

    AuditLogger::record('user.registered', 'user', 'u-1', null, ['email' => 'a@example.com']);

    $events = [];
    app(StreamBroker::class)->listen(StreamChannels::activity('org-null'), function (array $event) use (&$events) {
        $events[] = $event;
    }, 0);

    expect($events)->toBe([]);
});

it('streams activity.created events over SSE', function () {
    [$user, $organization] = activityContext();

    app(StreamBroker::class)->publish(StreamChannels::activity($organization->id), [
        'id' => 42,
        'type' => 'domain',
        'severity' => 'success',
        'title' => 'Streamed activity',
    ]);

    config()->set('omnex.activity.sse_max_seconds', 0);

    $response = $this->withHeader('X-Organization', $organization->id)
        ->get('/api/v1/activity/stream');

    ob_start();
    $response->baseResponse->sendContent();
    $output = ob_get_clean();

    $response->assertStatus(200);

    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache');

    expect($output)->toContain('event: activity.created')
        ->and($output)->toContain('Streamed activity');
});
