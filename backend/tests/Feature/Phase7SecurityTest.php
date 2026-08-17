<?php

use App\Models\Domain;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\SecurityFinding;
use App\Models\SecurityScoreSample;
use App\Models\Server;
use App\Models\Site;
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
function phase7Context(): array
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

// --- MFA enforcement policy -------------------------------------------------

it('reads and updates the MFA enforcement policy', function () {
    [$user, $organization] = phase7Context();

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/security/settings')
        ->assertOk()
        ->assertJsonPath('mfa_policy', 'optional');

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson('/api/v1/security/settings', ['mfa_policy' => 'required'])
        ->assertOk()
        ->assertJsonPath('mfa_policy', 'required');

    $this->assertDatabaseHas('organizations', [
        'id' => $organization->id,
        'mfa_policy' => 'required',
    ]);
});

it('validates the MFA policy value', function () {
    [$user, $organization] = phase7Context();

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson('/api/v1/security/settings', ['mfa_policy' => 'sometimes'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('mfa_policy');
});

it('requires security.manage to change the policy', function () {
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
        ->patchJson('/api/v1/security/settings', ['mfa_policy' => 'required'])
        ->assertForbidden();
});

it('emits an enforcement finding when MFA is required and members lack it', function () {
    [$user, $organization] = phase7Context();
    $organization->update(['mfa_policy' => 'required']);

    $response = $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/security')
        ->assertOk();

    $rules = collect($response->json('findings'))->pluck('rule');

    expect($rules)->toContain('mfa_enforcement')->toContain('mfa');

    $enforcement = collect($response->json('findings'))->firstWhere('rule', 'mfa_enforcement');
    expect($enforcement['severity'])->toBe('high');
    expect($enforcement['metadata']['affected_users'])->toHaveCount(1);

    // Once the member enables MFA, the enforcement finding resolves.
    $user->update(['mfa_enabled' => true]);

    $rules = collect($this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/security/scan')
        ->assertOk()
        ->json('findings'))
        ->pluck('rule');

    expect($rules)->not->toContain('mfa_enforcement');
});

// --- Session management ------------------------------------------------------

it('stamps issued tokens with IP and user agent', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);
    Organization::factory()->create();

    $this->withHeader('User-Agent', 'Mozilla/5.0 (session-test)')
        ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk();

    $this->assertDatabaseHas('personal_access_tokens', [
        'tokenable_id' => $user->id,
        'user_agent' => 'Mozilla/5.0 (session-test)',
    ]);
});

it('lists sessions with device context and flags the current one', function () {
    $user = User::factory()->create();
    $tokenA = $user->createToken('omnex-spa');
    $tokenA->accessToken->forceFill(['ip_address' => '10.0.0.1', 'user_agent' => 'UA-A'])->save();
    $tokenB = $user->createToken('omnex-spa');
    $tokenB->accessToken->forceFill(['ip_address' => '10.0.0.2', 'user_agent' => 'UA-B'])->save();

    $this->withToken($tokenA->plainTextToken);

    $sessions = $this->getJson('/api/v1/sessions')
        ->assertOk()
        ->assertJsonCount(2)
        ->json();

    $current = collect($sessions)->firstWhere('is_current', true);

    expect($current['ip_address'])->toBe('10.0.0.1');
    expect(collect($sessions)->pluck('ip_address'))->toContain('10.0.0.2');
});

it('revokes all other sessions', function () {
    $user = User::factory()->create();
    $tokenA = $user->createToken('omnex-spa');
    $tokenA->accessToken->forceFill(['ip_address' => '10.0.0.1', 'user_agent' => 'UA-A'])->save();
    $user->createToken('omnex-spa');
    $user->createToken('omnex-spa');

    $this->withToken($tokenA->plainTextToken);

    $this->deleteJson('/api/v1/sessions/others')->assertOk();

    $this->getJson('/api/v1/sessions')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.ip_address', '10.0.0.1');
});

it('revokes a single session', function () {
    $user = User::factory()->create();
    $tokenA = $user->createToken('omnex-spa');
    $tokenA->accessToken->forceFill(['ip_address' => '10.0.0.1', 'user_agent' => 'UA-A'])->save();
    $tokenB = $user->createToken('omnex-spa');
    $tokenB->accessToken->forceFill(['ip_address' => '10.0.0.2', 'user_agent' => 'UA-B'])->save();

    $this->withToken($tokenA->plainTextToken);

    $this->deleteJson('/api/v1/sessions/'.$tokenB->accessToken->id)->assertOk();

    $sessions = $this->getJson('/api/v1/sessions')->assertOk()->json();

    expect(collect($sessions)->pluck('id'))->not->toContain($tokenB->accessToken->id);
});

it('cannot revoke another user session', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $otherToken = $other->createToken('omnex-spa');
    $ownerToken = $owner->createToken('omnex-spa');

    $this->withToken($ownerToken->plainTextToken);

    $this->deleteJson('/api/v1/sessions/'.$otherToken->accessToken->id)->assertNotFound();
});

// --- SSL / vulnerability monitoring -----------------------------------------

it('monitors certificates and lists the checks', function () {
    [$user, $organization] = phase7Context();

    Site::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Insecure Site',
        'url' => 'http://insecure.example.com',
    ]);

    Domain::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'monitored.example.com',
    ]);

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/security')
        ->assertOk();

    $checks = $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/security/ssl-checks')
        ->assertOk()
        ->json();

    $types = collect($checks)->pluck('target_type');

    expect($types)->toContain('site')->toContain('domain');
    expect(collect($checks)->pluck('status'))->toContain('invalid'); // http site
    $this->assertDatabaseCount('ssl_checks', 2);
});

it('flags a site that is not served over HTTPS', function () {
    [$user, $organization] = phase7Context();

    Site::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Insecure Site',
        'url' => 'http://insecure.example.com',
    ]);

    $rules = collect($this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/security')
        ->assertOk()
        ->json('findings'))
        ->pluck('rule');

    expect($rules)->toContain('ssl_invalid');
});

it('flags certificates that are expiring or invalid', function () {
    [$user, $organization] = phase7Context();

    Domain::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'monitored.example.com',
    ]);

    // A wide warning window makes every deterministic sandbox check expiring.
    config(['omnex.security.ssl_warning_days' => 400]);

    $rules = collect($this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/security')
        ->assertOk()
        ->json('findings'))
        ->pluck('rule');

    expect($rules)->toContain('ssl_expiring');

    $this->assertDatabaseHas('ssl_checks', [
        'organization_id' => $organization->id,
        'target_type' => 'domain',
        'status' => 'expiring',
    ]);
});

// --- Backup status -----------------------------------------------------------

it('flags servers without scheduled backups', function () {
    [$user, $organization] = phase7Context();

    Server::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Prod-01',
        'snapshot_frequency' => 'disabled',
    ]);

    $rules = collect($this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/security')
        ->assertOk()
        ->json('findings'))
        ->pluck('rule');

    expect($rules)->toContain('backup_disabled');
});

it('resolves the backup finding when snapshots are enabled', function () {
    [$user, $organization] = phase7Context();

    Server::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Prod-01',
        'snapshot_frequency' => 'disabled',
    ]);

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/security')
        ->assertOk();

    Server::query()->first()->update(['snapshot_frequency' => 'daily']);

    $rules = collect($this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/security/scan')
        ->assertOk()
        ->json('findings'))
        ->pluck('rule');

    expect($rules)->not->toContain('backup_disabled');
});

// --- Score history / timeline -------------------------------------------------

it('records a score sample on scan and dismiss', function () {
    [$user, $organization] = phase7Context();

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/security')
        ->assertOk();

    expect(SecurityScoreSample::where('organization_id', $organization->id)->count())->toBeGreaterThanOrEqual(1);

    $finding = SecurityFinding::where('organization_id', $organization->id)->where('status', 'open')->first();
    expect($finding)->not->toBeNull();

    $before = SecurityScoreSample::where('organization_id', $organization->id)->count();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/security/findings/{$finding->id}/dismiss")
        ->assertOk();

    expect(SecurityScoreSample::where('organization_id', $organization->id)->count())->toBe($before + 1);
});

it('does not duplicate samples when the score is unchanged', function () {
    [$user, $organization] = phase7Context();

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/security')
        ->assertOk();

    $count = SecurityScoreSample::where('organization_id', $organization->id)->count();

    // A second plain scan with an identical score must not write a new row.
    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/security')
        ->assertOk();

    expect(SecurityScoreSample::where('organization_id', $organization->id)->count())->toBe($count);
});

it('exposes the score history chronologically', function () {
    [$user, $organization] = phase7Context();

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/security')
        ->assertOk();

    $samples = $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/security/history')
        ->assertOk()
        ->json('samples');

    expect($samples)->not->toBeEmpty();

    $scores = collect($samples)->pluck('score');
    expect($scores->sort()->values()->all())->toBe($scores->all())
        ->and($samples[0])->toHaveKeys(['score', 'open', 'high', 'medium', 'low', 'created_at']);
});
