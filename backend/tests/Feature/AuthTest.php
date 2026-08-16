<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
});

it('registers a new user', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('user.email', 'alice@example.com')
        ->assertJsonPath('user.locale', null)
        ->assertJsonStructure(['token', 'user']);
});

it('logs in with valid credentials', function () {
    $user = User::factory()->create(['password' => 'password123']);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password123',
    ])->assertOk()
        ->assertJsonStructure(['token', 'user'])
        ->assertJsonPath('user.locale', null);
});

it('returns the chosen locale in the login response', function () {
    $user = User::factory()->create(['password' => 'password123', 'locale' => 'fr']);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password123',
    ])->assertOk()->assertJsonPath('user.locale', 'fr');
});

it('rejects invalid credentials', function () {
    $user = User::factory()->create(['password' => 'password123']);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(422);
});

it('requires MFA verification when enabled', function () {
    $user = User::factory()->create([
        'password' => 'password123',
        'mfa_enabled' => true,
        'mfa_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
        'recovery_codes' => [hash('sha256', 'RECOVERYCODE1')],
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password123',
    ])->assertOk()
        ->assertJsonPath('mfa_required', true)
        ->assertJsonStructure(['mfa_token']);
});

it('completes MFA login with a recovery code', function () {
    $user = User::factory()->create([
        'password' => 'password123',
        'mfa_enabled' => true,
        'mfa_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
        'recovery_codes' => [hash('sha256', 'RECOVERYCODE1')],
    ]);

    $challenge = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password123',
    ])->json('mfa_token');

    $this->postJson('/api/v1/auth/mfa/verify', [
        'mfa_token' => $challenge,
        'recovery_code' => 'RECOVERYCODE1',
    ])->assertOk()->assertJsonStructure(['token', 'user']);
});

it('updates the user locale', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/me', ['locale' => 'fr'])
        ->assertOk()
        ->assertJsonPath('locale', 'fr');

    expect($user->fresh()->locale)->toBe('fr');
});

it('rejects an unsupported locale', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/me', ['locale' => 'xx'])->assertStatus(422);
});
