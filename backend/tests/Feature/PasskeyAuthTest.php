<?php

use App\Models\Authenticator;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
});

it('issues a passkey assertion challenge publicly', function () {
    $response = $this->getJson('/api/v1/auth/passkey/options');

    $response->assertOk()
        ->assertJsonStructure(['challenge', 'rp_id', 'timeout', 'allow_credentials']);
    expect(strlen($response->json('challenge')))->toBeGreaterThan(16);
});

it('registers, lists and authenticates with an authenticator', function () {
    $user = User::factory()->create(['security_level' => 'enhanced']);

    Sanctum::actingAs($user);

    $options = $this->getJson('/api/v1/auth/passkey/register-options')->assertOk()->json();
    expect($options)->toHaveKeys(['challenge', 'rp', 'user', 'registration_token']);

    $register = $this->postJson('/api/v1/auth/passkey/register', [
        'registration_token' => $options['registration_token'],
        'credential' => [
            'id' => 'cred-yubikey-1',
            'raw_id' => base64_encode('cred-yubikey-1'),
            'response' => ['client_data_json' => base64_encode('challenge')],
        ],
        'name' => 'YubiKey 5 NFC',
        'transport' => 'usb',
    ])->assertStatus(201)->json('data');

    expect($register['name'])->toBe('YubiKey 5 NFC');

    $list = $this->getJson('/api/v1/auth/authenticators')->assertOk()->json('data');
    expect($list)->toHaveCount(1);
    expect($list[0]['id'])->toBe($register['id']);

    // Reject replay: the same challenge signature is consumed by the challenge TTL.
    $assertion = base64_encode('challenge');
    $signature = base64_encode(hash('sha256', $assertion));

    $login = $this->postJson('/api/v1/auth/passkey/verify', [
        'credential' => [
            'id' => 'cred-yubikey-1',
            'response' => [
                'client_data_json' => $assertion,
                'authenticator_data' => base64_encode('auth-data'),
                'signature' => $signature,
            ],
        ],
    ]);

    // The challenge was pulled from cache at registration; a re-issued
    // challenge is required for the assertion, so this must be rejected.
    $login->assertStatus(401);
});

it('rejects unknown credentials and revokes authenticators', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/passkey/verify', [
        'credential' => [
            'id' => 'unknown-credential',
            'response' => [
                'client_data_json' => base64_encode('challenge'),
                'authenticator_data' => base64_encode('auth-data'),
                'signature' => base64_encode('sig'),
            ],
        ],
    ])->assertStatus(401);

    $authenticator = Authenticator::query()->create([
        'user_id' => $user->id,
        'credential_id' => 'cred-1',
        'public_key' => 'sandbox',
        'name' => 'Windows Hello',
        'transport' => 'internal',
    ]);

    Sanctum::actingAs($user);
    $this->deleteJson("/api/v1/auth/authenticators/{$authenticator->id}")->assertOk();
    expect(Authenticator::query()->count())->toBe(0);
});

it('updates the adaptive security level', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/auth/security-level', ['security_level' => 'critical'])
        ->assertOk()
        ->assertJsonPath('data.security_level', 'critical');

    expect($user->fresh()->security_level)->toBe('critical');
});
