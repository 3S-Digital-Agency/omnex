<?php

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Server;
use App\Models\SshKey;
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
function sshKeyContext(): array
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

const TEST_PUBLIC_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIP7bJ2FZQnXr6gJkYxQnMlpHJ2vQ0mWtXyRfZk1aB2c test';

it('creates an ssh key with a fingerprint and lists it', function () {
    [$user, $organization] = sshKeyContext();

    $response = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud/ssh-keys', [
            'name' => 'Laptop',
            'public_key' => TEST_PUBLIC_KEY,
        ])
        ->assertStatus(201)
        ->assertJsonPath('name', 'Laptop');

    expect($response->json('fingerprint'))->toStartWith('SHA256:');

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/cloud/ssh-keys')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Laptop');
});

it('rejects an invalid public key', function () {
    [$user, $organization] = sshKeyContext();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud/ssh-keys', [
            'name' => 'Broken',
            'public_key' => 'not-a-real-key',
        ])->assertStatus(422);
});

it('rejects a duplicate public key within the organization', function () {
    [$user, $organization] = sshKeyContext();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud/ssh-keys', [
            'name' => 'Laptop',
            'public_key' => TEST_PUBLIC_KEY,
        ])->assertStatus(201);

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud/ssh-keys', [
            'name' => 'Laptop copy',
            'public_key' => TEST_PUBLIC_KEY,
        ])->assertStatus(422);
});

it('generates a key pair and returns the private key exactly once', function () {
    [$user, $organization] = sshKeyContext();

    $response = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud/ssh-keys/generate', [
            'name' => 'Deploy bot',
            'type' => 'ed25519',
        ])
        ->assertStatus(201);

    expect($response->json('private_key'))->toStartWith('-----BEGIN OPENSSH PRIVATE KEY-----');
    expect($response->json('data.public_key'))->toStartWith('ssh-ed25519 AAAA');
    expect($response->json('data.fingerprint'))->toStartWith('SHA256:');

    $keyId = $response->json('data.id');

    // The registered key only holds the public half.
    $stored = SshKey::withoutTenancy()->find($keyId);

    expect($stored->public_key)->toBe($response->json('data.public_key'))
        ->and($stored->public_key)->not->toContain('PRIVATE KEY');

    // The private key is not retrievable afterwards — it was never stored.
    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/cloud/ssh-keys')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Deploy bot')
        ->assertJsonMissingPath('data.0.private_key');
});

it('generates rsa keys and rejects unknown key types', function () {
    [$user, $organization] = sshKeyContext();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud/ssh-keys/generate', [
            'name' => 'Backup host',
            'type' => 'rsa',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.public_key', fn ($value) => str_starts_with($value, 'ssh-rsa '));

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud/ssh-keys/generate', [
            'name' => 'Weird',
            'type' => 'dsa',
        ])->assertStatus(422);
});

it('seals a generated private key in the vault and unlocks it with the password', function () {
    [$user, $organization] = sshKeyContext();

    $response = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud/ssh-keys/generate', [
            'name' => 'Deploy bot',
            'type' => 'ed25519',
            'vault_password' => 'vault-pass-123',
        ])
        ->assertStatus(201);

    $keyId = $response->json('data.id');

    expect($response->json('data.has_private_key'))->toBeTrue()
        ->and($response->json('data.vault_enabled_at'))->not->toBeNull();

    // A wrong password is rejected.
    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/cloud/ssh-keys/{$keyId}/unlock", [
            'vault_password' => 'wrong-password',
        ])->assertStatus(422);

    // The correct password recovers the private key exactly once.
    $unlock = $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/cloud/ssh-keys/{$keyId}/unlock", [
            'vault_password' => 'vault-pass-123',
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $keyId);

    expect($unlock->json('private_key'))->toStartWith('-----BEGIN OPENSSH PRIVATE KEY-----')
        ->and($unlock->json('data.has_private_key'))->toBeTrue();

    // The plaintext is never exposed through the listing.
    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/cloud/ssh-keys')
        ->assertOk()
        ->assertJsonMissingPath('data.0.private_key')
        ->assertJsonMissingPath('data.0.encrypted_private_key');
});

it('requires cloud.manage to unlock a vaulted key', function () {
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
        ->postJson('/api/v1/cloud/ssh-keys/some-key/unlock', [
            'vault_password' => 'whatever',
        ])->assertStatus(403);
});

it('installs a saved key on a server through the provider', function () {
    [$user, $organization] = sshKeyContext();

    $key = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud/ssh-keys', [
            'name' => 'Deploy key',
            'public_key' => TEST_PUBLIC_KEY,
        ])->assertStatus(201)->json('id');

    $server = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'keyed-01',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])->assertStatus(201)->json('id');

    $install = $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/cloud/{$server}/ssh-key", [
            'ssh_key_id' => $key,
        ])
        ->assertStatus(201)
        ->assertJsonPath('status', 'installed');

    // The server now references the saved key and its normalized body.
    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/cloud/{$server}")
        ->assertOk()
        ->assertJsonPath('ssh_key_id', $key)
        ->assertJsonPath('ssh_key', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIP7bJ2FZQnXr6gJkYxQnMlpHJ2vQ0mWtXyRfZk1aB2c');

    // The operation trail records the install_key operation (provision and
    // install share the same second, so match by type rather than position).
    $operations = $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/cloud/{$server}/operations")
        ->assertOk()
        ->json('data');

    $installOperation = collect($operations)->firstWhere('type', 'install_key');

    expect($installOperation)->not->toBeNull()
        ->and($installOperation['status'])->toBe('succeeded');
});

it('reports how many servers use a key and blocks deleting it while in use', function () {
    [$user, $organization] = sshKeyContext();

    $key = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud/ssh-keys', [
            'name' => 'Deploy key',
            'public_key' => TEST_PUBLIC_KEY,
        ])->assertStatus(201);

    // No server references the key yet → count 0 and deletion allowed.
    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/cloud/ssh-keys')
        ->assertOk()
        ->assertJsonPath('data.0.servers_count', 0);

    $server = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'inuse-01',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
            'ssh_key_id' => $key->json('id'),
        ])->assertStatus(201)->json('id');

    // The key is now referenced by one server.
    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/cloud/ssh-keys')
        ->assertOk()
        ->assertJsonPath('data.0.servers_count', 1);

    // Deleting a key in use is rejected with a 422 and the key survives.
    $this->withHeader('X-Organization', $organization->id)
        ->deleteJson("/api/v1/cloud/ssh-keys/{$key->json('id')}")
        ->assertStatus(422);

    expect(SshKey::withoutTenancy()->find($key->json('id')))->not->toBeNull();

    // After the server is deleted, the key can be removed.
    $this->withHeader('X-Organization', $organization->id)
        ->deleteJson("/api/v1/cloud/{$server}")
        ->assertStatus(204);

    $this->withHeader('X-Organization', $organization->id)
        ->deleteJson("/api/v1/cloud/ssh-keys/{$key->json('id')}")
        ->assertStatus(204);

    expect(SshKey::withoutTenancy()->find($key->json('id')))->toBeNull();
});

it('enforces cloud.manage on server key installs', function () {
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
        ->postJson('/api/v1/cloud/some-server/ssh-key', [
            'ssh_key_id' => 'ssh-key-id',
        ])->assertStatus(403);
});

it('isolates key installs between tenants', function () {
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
            'name' => 'srv-b',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
        ])->assertStatus(201)->json('id');

    Sanctum::actingAs($userA);

    // Org A cannot install onto org B's server.
    $this->withHeader('X-Organization', $orgA->id)
        ->postJson("/api/v1/cloud/{$server}/ssh-key", [
            'ssh_key_id' => 'any-key',
        ])->assertStatus(404);
});

it('requires cloud.manage to generate keys', function () {
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
        ->postJson('/api/v1/cloud/ssh-keys/generate', [
            'name' => 'Denied',
        ])->assertStatus(403);
});

it('renames and deletes an ssh key', function () {
    [$user, $organization] = sshKeyContext();

    $key = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud/ssh-keys', [
            'name' => 'Old name',
            'public_key' => TEST_PUBLIC_KEY,
        ])->assertStatus(201)->json('id');

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson("/api/v1/cloud/ssh-keys/{$key}", ['name' => 'Work laptop'])
        ->assertOk()
        ->assertJsonPath('name', 'Work laptop');

    $this->withHeader('X-Organization', $organization->id)
        ->deleteJson("/api/v1/cloud/ssh-keys/{$key}")
        ->assertStatus(204);

    expect(SshKey::withoutTenancy()->find($key))->toBeNull();
});

it('associates a saved ssh key with a server at creation', function () {
    [$user, $organization] = sshKeyContext();

    $key = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud/ssh-keys', [
            'name' => 'Deploy key',
            'public_key' => TEST_PUBLIC_KEY,
        ])->assertStatus(201)->json('id');

    $server = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'keyed-01',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
            'ssh_key_id' => $key,
        ])->assertStatus(201);

    expect($server->json('ssh_key_id'))->toBe($key);
    // The comment is stripped: only type + base64 body are normalized/stored.
    expect($server->json('ssh_key'))->toBe('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIP7bJ2FZQnXr6gJkYxQnMlpHJ2vQ0mWtXyRfZk1aB2c');

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/cloud/{$server->json('id')}")
        ->assertOk()
        ->assertJsonPath('ssh_key_id', $key);
});

it('enforces ssh key permissions', function () {
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
        ->getJson('/api/v1/cloud/ssh-keys')
        ->assertOk();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/cloud/ssh-keys', [
            'name' => 'Denied',
            'public_key' => TEST_PUBLIC_KEY,
        ])->assertStatus(403);
});

it('isolates ssh keys between tenants', function () {
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

    $key = $this->withHeader('X-Organization', $orgB->id)
        ->postJson('/api/v1/cloud/ssh-keys', [
            'name' => 'Private',
            'public_key' => TEST_PUBLIC_KEY,
        ])->assertStatus(201)->json('id');

    Sanctum::actingAs($userA);

    $this->withHeader('X-Organization', $orgA->id)
        ->patchJson("/api/v1/cloud/ssh-keys/{$key}", ['name' => 'Intruder'])
        ->assertStatus(404);

    // A server in org A cannot reference org B's key.
    $this->withHeader('X-Organization', $orgA->id)
        ->postJson('/api/v1/cloud', [
            'name' => 'cross-tenant',
            'region' => 'fsn1',
            'plan' => 'cpx11',
            'image' => 'ubuntu-24.04',
            'ssh_key_id' => $key,
        ])->assertStatus(404);
});
