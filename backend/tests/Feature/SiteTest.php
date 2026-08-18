<?php

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
});

/**
 * @return array{0: User, 1: Organization}
 */
function sitesContext(): array
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

it('lists the site providers', function () {
    [$user, $organization] = sitesContext();

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/sites/providers')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'sandbox')
        ->assertJsonPath('data.0.configured', true)
        ->assertJsonPath('data.1.name', 'custom')
        ->assertJsonPath('data.1.configured', false);
});

it('creates a site and never leaks environment variables', function () {
    [$user, $organization] = sitesContext();

    $response = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/sites', [
            'name' => 'Marketing',
            'framework' => 'static',
            'git_url' => 'https://github.com/acme/marketing.git',
            'environment_variables' => [
                'API_SECRET' => 'super-secret-token',
                'PUBLIC_FLAG' => 'yes',
            ],
        ])
        ->assertStatus(201)
        ->assertJsonPath('name', 'Marketing')
        ->assertJsonPath('status', 'provisioning')
        ->assertJsonPath('environment_variable_keys', ['API_SECRET', 'PUBLIC_FLAG']);

    $json = json_encode($response->json());
    expect($json)->not->toContain('super-secret-token');

    // Stored encrypted at rest.
    $site = Site::withoutTenancy()->findOrFail($response->json('id'));
    $raw = DB::table('sites')->where('id', $site->id)->value('environment_variables');
    expect($raw)->not->toContain('super-secret-token');

    // Decrypted server-side on demand.
    expect($site->environment()['API_SECRET'])->toBe('super-secret-token');
});

it('deploys a site and records a live deployment', function () {
    [$user, $organization] = sitesContext();

    $site = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/sites', [
            'name' => 'Docs',
            'framework' => 'next',
            'git_url' => 'https://github.com/acme/docs.git',
        ])->assertStatus(201)->json('id');

    $deployment = $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/sites/{$site}/deployments")
        ->assertStatus(201)
        ->assertJsonPath('status', 'live')
        ->assertJsonPath('number', 1)
        ->assertJsonStructure(['commit_sha', 'url', 'logs']);

    expect($deployment->json('commit_sha'))->not->toBeNull();

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/sites/{$site}")
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('current_deployment_id', $deployment->json('id'));

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/sites/{$site}/deployments")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('auto-rolls back to the previous live deployment on failure', function () {
    [$user, $organization] = sitesContext();

    $site = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/sites', [
            'name' => 'Rollback',
            'framework' => 'static',
            'git_url' => 'https://github.com/acme/rollback.git',
        ])->assertStatus(201)->json('id');

    $good = $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/sites/{$site}/deployments")
        ->assertStatus(201)
        ->json('id');

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson("/api/v1/sites/{$site}", ['git_branch' => 'fail'])
        ->assertOk();

    $failed = $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/sites/{$site}/deployments")
        ->assertStatus(200)
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('number', 2)
        ->json('id');

    expect($failed)->not->toBe($good);

    // Site still serves the previous live deployment.
    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/sites/{$site}")
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('current_deployment_id', $good);
});

it('rolls back manually to an earlier deployment', function () {
    [$user, $organization] = sitesContext();

    $site = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/sites', [
            'name' => 'Manual',
            'framework' => 'laravel',
            'git_url' => 'https://github.com/acme/manual.git',
        ])->assertStatus(201)->json('id');

    $first = $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/sites/{$site}/deployments")
        ->assertStatus(201)->json('id');

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson("/api/v1/sites/{$site}", ['git_branch' => 'main-v2'])
        ->assertOk();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/sites/{$site}/deployments")
        ->assertStatus(201);

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/sites/{$site}/deployments/{$first}/rollback")
        ->assertOk()
        ->assertJsonPath('id', $first);

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/sites/{$site}")
        ->assertOk()
        ->assertJsonPath('current_deployment_id', $first);
});

it('resolves a deployment preview url and persists it on the deployment', function () {
    [$user, $organization] = sitesContext();

    $site = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/sites', [
            'name' => 'Preview',
            'framework' => 'static',
            'git_url' => 'https://github.com/acme/preview.git',
        ])->assertStatus(201)->json('id');

    $deployment = $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/sites/{$site}/deployments")
        ->assertStatus(201)
        ->json();

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/sites/{$site}/deployments/{$deployment['id']}/preview")
        ->assertOk()
        ->assertJsonStructure(['url', 'aliases']);

    // The preview URL is now persisted on the deployment record.
    $persisted = $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/sites/{$site}/deployments/{$deployment['id']}")
        ->assertOk()
        ->json('preview_url');

    expect($persisted)->toStartWith('https://'.$deployment['commit_sha'].'.')
        ->toEndWith('.omnex-sites.test');
});

it('rejects a rollback to a non-live deployment', function () {
    [$user, $organization] = sitesContext();

    $site = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/sites', [
            'name' => 'Guard',
            'framework' => 'static',
            'git_url' => 'https://github.com/acme/guard.git',
            'git_branch' => 'fail',
        ])->assertStatus(201)->json('id');

    // First (and only) deploy fails deterministically.
    $failed = $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/sites/{$site}/deployments")
        ->assertStatus(200)
        ->assertJsonPath('status', 'failed')
        ->json('id');

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/sites/{$site}/deployments/{$failed}/rollback")
        ->assertStatus(422);
});

it('updates a site and replaces environment variables', function () {
    [$user, $organization] = sitesContext();

    $site = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/sites', [
            'name' => 'Env',
            'framework' => 'static',
            'git_url' => 'https://github.com/acme/env.git',
            'environment_variables' => ['OLD' => 'value'],
        ])->assertStatus(201)->json('id');

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson("/api/v1/sites/{$site}", [
            'name' => 'Env Renamed',
            'environment_variables' => ['NEW' => 'other'],
        ])
        ->assertOk()
        ->assertJsonPath('name', 'Env Renamed')
        ->assertJsonPath('environment_variable_keys', ['NEW']);

    expect(Site::withoutTenancy()->findOrFail($site)->environment()['NEW'])->toBe('other');
});

it('deletes a site and its deployments', function () {
    [$user, $organization] = sitesContext();

    $site = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/sites', [
            'name' => 'Gone',
            'framework' => 'static',
            'git_url' => 'https://github.com/acme/gone.git',
        ])->assertStatus(201)->json('id');

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/sites/{$site}/deployments")
        ->assertStatus(201);

    $this->withHeader('X-Organization', $organization->id)
        ->deleteJson("/api/v1/sites/{$site}")
        ->assertStatus(204);

    expect(Site::withoutTenancy()->find($site))->toBeNull();
    expect(SiteDeployment::withoutTenancy()->where('site_id', $site)->exists())->toBeFalse();
});

it('enforces sites permissions', function () {
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
        ->getJson('/api/v1/sites')
        ->assertOk();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/sites', [
            'name' => 'Denied',
            'framework' => 'static',
            'git_url' => 'https://github.com/acme/denied.git',
        ])
        ->assertStatus(403);
});

it('isolates sites between tenants', function () {
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

    $site = $this->withHeader('X-Organization', $orgB->id)
        ->postJson('/api/v1/sites', [
            'name' => 'Private',
            'framework' => 'static',
            'git_url' => 'https://github.com/acme/private.git',
        ])->assertStatus(201)->json('id');

    Sanctum::actingAs($userA);

    $this->withHeader('X-Organization', $orgA->id)
        ->getJson("/api/v1/sites/{$site}")
        ->assertStatus(404);
});
