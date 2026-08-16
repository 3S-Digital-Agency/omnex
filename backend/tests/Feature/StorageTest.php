<?php

use App\Models\DriveFile;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Storage\StorageService;
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
function driveContext(): array
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

function b64(string $contents): string
{
    return base64_encode($contents);
}

it('lists the storage providers', function () {
    [$user, $organization] = driveContext();

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/storage/providers')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'sandbox')
        ->assertJsonPath('data.0.configured', true)
        ->assertJsonPath('data.1.name', 's3')
        ->assertJsonPath('data.1.configured', false);
});

it('lists an empty drive with quota', function () {
    [$user, $organization] = driveContext();

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/storage')
        ->assertOk()
        ->assertJsonPath('folder', null)
        ->assertJsonCount(0, 'folders')
        ->assertJsonCount(0, 'files')
        ->assertJsonPath('quota.used', 0);
});

it('creates, renames and navigates folders', function () {
    [$user, $organization] = driveContext();

    $parent = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/storage/folders', ['name' => 'Projects'])
        ->assertStatus(201)
        ->assertJsonPath('name', 'Projects');

    $parentId = $parent->json('id');

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/storage/folders', ['name' => 'Nested', 'parent_id' => $parentId])
        ->assertStatus(201)
        ->assertJsonPath('parent_id', $parentId);

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson("/api/v1/storage/folders/{$parentId}", ['name' => 'Work'])
        ->assertOk()
        ->assertJsonPath('name', 'Work');

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/storage/folders/{$parentId}")
        ->assertOk()
        ->assertJsonPath('folder.name', 'Work')
        ->assertJsonCount(1, 'folders')
        ->assertJsonPath('folders.0.name', 'Nested');
});

it('refuses to delete a non-empty folder', function () {
    [$user, $organization] = driveContext();

    $folder = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/storage/folders', ['name' => 'Busy'])
        ->assertStatus(201);

    $folderId = $folder->json('id');

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/storage/files', [
            'folder_id' => $folderId,
            'name' => 'keep.txt',
            'contents' => b64('x'),
        ])->assertStatus(201);

    $this->withHeader('X-Organization', $organization->id)
        ->deleteJson("/api/v1/storage/folders/{$folderId}")
        ->assertStatus(422);
});

it('uploads, downloads and versions a file', function () {
    [$user, $organization] = driveContext();

    $file = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/storage/files', [
            'name' => 'notes.txt',
            'contents' => b64('hello world'),
            'mime_type' => 'text/plain',
        ])
        ->assertStatus(201)
        ->assertJsonPath('name', 'notes.txt')
        ->assertJsonPath('version', 1)
        ->assertJsonPath('size', 11);

    $fileId = $file->json('id');

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/storage/files/{$fileId}/download")
        ->assertOk()
        ->assertJsonPath('name', 'notes.txt')
        ->assertJsonPath('size', 11)
        ->assertJsonStructure(['url', 'name', 'mime_type', 'size']);

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson("/api/v1/storage/files/{$fileId}", ['contents' => b64('hello v2')])
        ->assertOk()
        ->assertJsonPath('version', 2)
        ->assertJsonPath('size', 8);

    $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/storage/files/{$fileId}/versions")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('restores an older version as a new one', function () {
    [$user, $organization] = driveContext();

    $file = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/storage/files', ['name' => 'doc.txt', 'contents' => b64('one')])
        ->assertStatus(201);

    $fileId = $file->json('id');

    $this->withHeader('X-Organization', $organization->id)
        ->patchJson("/api/v1/storage/files/{$fileId}", ['contents' => b64('two')])
        ->assertOk();

    $versions = $this->withHeader('X-Organization', $organization->id)
        ->getJson("/api/v1/storage/files/{$fileId}/versions")
        ->assertOk()
        ->json('data');

    $v1 = collect($versions)->firstWhere('version', 1);

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/storage/files/{$fileId}/versions/{$v1['id']}/restore")
        ->assertOk()
        ->assertJsonPath('version', 3)
        ->assertJsonPath('size', 3);

    $model = DriveFile::withoutTenancy()->findOrFail($fileId);

    expect(app(StorageService::class)->contents($model))->toBe('one');
});

it('trashes, restores and permanently deletes a file', function () {
    [$user, $organization] = driveContext();

    $file = $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/storage/files', ['name' => 'rm.txt', 'contents' => b64('bye')])
        ->assertStatus(201);

    $fileId = $file->json('id');

    $this->withHeader('X-Organization', $organization->id)
        ->deleteJson("/api/v1/storage/files/{$fileId}")
        ->assertOk()
        ->assertJsonPath('status', 'trashed');

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/storage/trash')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/storage/files/{$fileId}/restore")
        ->assertOk()
        ->assertJsonPath('status', 'active');

    $this->withHeader('X-Organization', $organization->id)
        ->deleteJson("/api/v1/storage/files/{$fileId}")
        ->assertOk();

    $this->withHeader('X-Organization', $organization->id)
        ->deleteJson("/api/v1/storage/trash/{$fileId}")
        ->assertStatus(204);

    expect(DriveFile::withoutTenancy()->find($fileId))->toBeNull();
});

it('rejects a quota-exceeding upload', function () {
    [$user, $organization] = driveContext();

    config()->set('omnex.storage.default_quota_bytes', 10);

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/storage/files', ['name' => 'big.bin', 'contents' => b64(str_repeat('a', 20))])
        ->assertStatus(422);
});

it('enforces storage permissions', function () {
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
        ->getJson('/api/v1/storage')
        ->assertOk();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson('/api/v1/storage/folders', ['name' => 'Denied'])
        ->assertStatus(403);
});

it('isolates the drive between tenants', function () {
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

    $folder = $this->withHeader('X-Organization', $orgB->id)
        ->postJson('/api/v1/storage/folders', ['name' => 'Private'])
        ->assertStatus(201);

    Sanctum::actingAs($userA);

    $this->withHeader('X-Organization', $orgA->id)
        ->getJson("/api/v1/storage/folders/{$folder->json('id')}")
        ->assertStatus(404);
});
