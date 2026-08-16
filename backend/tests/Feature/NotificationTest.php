<?php

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Notifications\NotificationService;
use App\Support\Streams\StreamBroker;
use App\Support\Streams\StreamChannels;
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
function notificationsContext(): array
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

it('lists notifications newest first with severity and route', function () {
    [$user, $organization] = notificationsContext();

    $this->travelTo(now()->startOfHour());

    NotificationService::send(
        $user->id,
        'welcome',
        'Welcome',
        'Your organization is ready.',
        ['route' => '/'],
        $organization->id,
        'info',
    );

    $this->travel(1)->hour();

    NotificationService::send(
        $user->id,
        'domain.expiring',
        'Domain expiring',
        'omnex.dev expires soon.',
        ['domain_id' => 'dom-1', 'route' => '/domains/dom-1'],
        $organization->id,
        'warning',
    );

    $this->travelBack();

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('unread', 2)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', 'domain.expiring')
        ->assertJsonPath('data.0.severity', 'warning')
        ->assertJsonPath('data.0.route', '/domains/dom-1')
        ->assertJsonPath('data.1.type', 'welcome')
        ->assertJsonPath('data.1.severity', 'info');
});

it('marks a single notification read', function () {
    [$user, $organization] = notificationsContext();

    $notification = NotificationService::send(
        $user->id,
        'system',
        'Notice',
        'Something happened.',
        [],
        $organization->id,
        'info',
    );

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertJsonPath('read_at', fn ($value) => $value !== null);

    // Idempotent: marking again keeps the original read timestamp.
    $first = $notification->fresh()->read_at;

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/notifications/{$notification->id}/read")
        ->assertOk();

    expect($notification->fresh()->read_at->toIso8601String())->toBe($first->toIso8601String());

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/notifications')
        ->assertJsonPath('unread', 0);
});

it('marks all notifications read', function () {
    [$user, $organization] = notificationsContext();

    foreach (['one', 'two', 'three'] as $title) {
        NotificationService::send($user->id, 'system', $title, null, [], $organization->id, 'info');
    }

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('updated', 3);

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/notifications')
        ->assertJsonPath('unread', 0);
});

it('isolates notifications between users and tenants', function () {
    $userA = User::factory()->create();
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $userB = User::factory()->create();

    foreach ([[$userA, $orgA], [$userB, $orgB]] as [$user, $org]) {
        Membership::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role_id' => Role::where('key', 'owner')->firstOrFail()->id,
            'status' => 'active',
        ]);
    }

    NotificationService::send($userA->id, 'system', 'For A', null, [], $orgA->id, 'info');
    // Same user, but attached to another tenant — must not leak into org A.
    NotificationService::send($userA->id, 'system', 'Cross tenant', null, [], $orgB->id, 'info');

    Sanctum::actingAs($userB);

    $this->withHeader('X-Organization', $orgB->id)
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    Sanctum::actingAs($userA);

    $this->withHeader('X-Organization', $orgA->id)
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'For A');
});

it('rejects reading another user notification', function () {
    $userA = User::factory()->create();
    $org = Organization::factory()->create();
    $userB = User::factory()->create();

    foreach ([$userA, $userB] as $user) {
        Membership::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role_id' => Role::where('key', 'owner')->firstOrFail()->id,
            'status' => 'active',
        ]);
    }

    $notification = NotificationService::send($userA->id, 'system', 'Private', null, [], $org->id, 'info');

    Sanctum::actingAs($userB);

    $this->withHeader('X-Organization', $org->id)
        ->postJson("/api/v1/notifications/{$notification->id}/read")
        ->assertStatus(404);
});

it('broadcasts a stream event when a notification is sent', function () {
    [$user, $organization] = notificationsContext();

    NotificationService::send(
        $user->id,
        'domain.expiring',
        'Domain expiring',
        null,
        ['domain_id' => 'dom-1', 'route' => '/domains/dom-1'],
        $organization->id,
        'warning',
    );

    $events = [];
    app(StreamBroker::class)->listen(StreamChannels::notifications($user->id), function (array $event) use (&$events) {
        $events[] = $event;
    }, 0);

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('domain.expiring')
        ->and($events[0]['severity'])->toBe('warning')
        ->and($events[0]['route'])->toBe('/domains/dom-1');
});

it('filters and paginates notifications', function () {
    [$user, $organization] = notificationsContext();

    $this->travelTo(now()->startOfHour());

    NotificationService::send($user->id, 'system', 'Sys info', null, [], $organization->id, 'info');
    $this->travel(1)->minute();
    NotificationService::send($user->id, 'domain', 'Domain A', null, [], $organization->id, 'warning');
    $this->travel(1)->minute();
    NotificationService::send($user->id, 'deployment', 'Deploy A', null, [], $organization->id, 'success');
    $this->travel(1)->minute();
    NotificationService::send($user->id, 'security', 'Security A', null, [], $organization->id, 'danger');
    $this->travelBack();

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/notifications?type=domain')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Domain A');

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/notifications?severity=warning')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Domain A');

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/notifications?unread=1')
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('meta.total', 4);

    // Newest first: page 2 holds the two oldest items.
    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/notifications?per_page=2&page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('data.0.title', 'Domain A')
        ->assertJsonPath('data.1.title', 'Sys info');
});

it('streams notification.created events over SSE', function () {
    [$user, $organization] = notificationsContext();

    app(StreamBroker::class)->publish(StreamChannels::notifications($user->id), [
        'id' => 'notif-stream',
        'type' => 'system',
        'severity' => 'info',
        'title' => 'Streamed',
    ]);

    config()->set('omnex.notifications.sse_max_seconds', 0);

    $response = $this->withHeader('X-Organization', $organization->id)
        ->get('/api/v1/notifications/stream');

    ob_start();
    $response->baseResponse->sendContent();
    $output = ob_get_clean();

    $response->assertStatus(200);

    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache');

    expect($output)->toContain('event: notification.created')
        ->and($output)->toContain('Streamed');
});
