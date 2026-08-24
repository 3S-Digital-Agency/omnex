<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Activity\ActivityPresenter;
use Carbon\CarbonImmutable;

it('classifies representative actions from every activity group', function () {
    $expectations = [
        'user.logged_in' => ['auth', 'info', 'Sign in'],
        'user.mfa_disabled' => ['security', 'warning', 'MFA disabled'],
        'organization.created' => ['organization', 'success', 'Organization created'],
        'member.role_changed' => ['member', 'warning', 'Role changed'],
        'domain.expiring' => ['domain', 'warning', 'Domain expiring'],
        'dns.record_created' => ['dns', 'info', 'DNS record created'],
        'drive.file_uploaded' => ['storage', 'success', 'File uploaded'],
        'site.deploy_failed' => ['deployment', 'danger', 'Deployment failed'],
        'security.finding_reopened' => ['security', 'warning', 'Finding reopened'],
    ];

    foreach ($expectations as $action => $expected) {
        expect(ActivityPresenter::classify($action))->toBe($expected);
    }
});

it('falls back to a system info activity for unknown actions', function () {
    expect(ActivityPresenter::classify('server.restarted'))
        ->toBe(['system', 'info', 'server.restarted']);
});

it('describes an after email address', function () {
    $log = AuditLog::make(['after' => ['email' => 'after@example.com']]);

    expect(ActivityPresenter::describe($log))->toBe('after@example.com');
});

it('describes a before email address when after has no email', function () {
    $log = AuditLog::make([
        'before' => ['email' => 'before@example.com'],
        'after' => ['status' => 'inactive'],
    ]);

    expect(ActivityPresenter::describe($log))->toBe('before@example.com');
});

it('describes an after name', function () {
    $log = AuditLog::make(['after' => ['name' => 'Example folder']]);

    expect(ActivityPresenter::describe($log))->toBe('Example folder');
});

it('describes a DNS snapshot with default values', function () {
    $log = AuditLog::make(['after' => ['type' => 'TXT']]);

    expect(ActivityPresenter::describe($log))->toBe('TXT @ → ');
});

it('describes a before DNS snapshot when no after snapshot is available', function () {
    $log = AuditLog::make([
        'before' => ['type' => 'A', 'name' => 'www', 'content' => '203.0.113.5'],
    ]);

    expect(ActivityPresenter::describe($log))->toBe('A www → 203.0.113.5');
});

it('falls back to the resource type and id in descriptions', function () {
    $log = AuditLog::make([
        'resource_type' => 'domain',
        'resource_id' => 'domain-123',
    ]);

    expect(ActivityPresenter::describe($log))->toBe('domain domain-123');
});

it('returns no description when a log has no resource type', function () {
    $log = AuditLog::make(['resource_id' => 'resource-123']);

    expect(ActivityPresenter::describe($log))->toBeNull();
});

it('presents every activity field with its related actor and ISO timestamp', function () {
    $createdAt = CarbonImmutable::create(2025, 2, 3, 4, 5, 6, 'UTC');
    $log = AuditLog::make([
        'action' => 'site.deployed',
        'after' => ['name' => 'Marketing site'],
    ]);
    $log->id = 42;
    $log->created_at = $createdAt;
    $log->setRelation('user', User::make(['name' => 'Taylor Example']));

    expect(ActivityPresenter::toArray($log))->toBe([
        'id' => 42,
        'type' => 'deployment',
        'severity' => 'success',
        'title' => 'Deployment completed',
        'description' => 'Marketing site',
        'actor' => 'Taylor Example',
        'created_at' => $createdAt->toIso8601String(),
    ]);
});

it('presents null actor and timestamp when they are unavailable', function () {
    $log = AuditLog::make([
        'action' => 'user.logged_out',
        'resource_type' => 'user',
        'resource_id' => 'user-123',
    ]);
    $log->id = 43;

    expect(ActivityPresenter::toArray($log))->toBe([
        'id' => 43,
        'type' => 'auth',
        'severity' => 'info',
        'title' => 'Sign out',
        'description' => 'user user-123',
        'actor' => null,
        'created_at' => null,
    ]);
});
