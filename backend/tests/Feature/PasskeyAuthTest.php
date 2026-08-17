<?php

use App\Models\Authenticator;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\Support\WebAuthnTestKit;

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

it('registers, lists and authenticates with a real ES256 attestation', function () {
    $user = User::factory()->create(['security_level' => 'enhanced']);

    Sanctum::actingAs($user);

    $options = $this->getJson('/api/v1/auth/passkey/register-options')->assertOk()->json();
    expect($options)->toHaveKeys(['challenge', 'rp', 'user', 'registration_token']);

    $kit = WebAuthnTestKit::keyPair();
    $credentialId = random_bytes(32);
    $rpIdHash = hash('sha256', 'localhost', true);
    $coseKey = WebAuthnTestKit::cosePublicKey($kit['x'], $kit['y']);
    $authData = WebAuthnTestKit::authenticatorData($rpIdHash, 0x41, 0, $credentialId, $coseKey);
    $clientDataJSON = WebAuthnTestKit::clientDataJSON('webauthn.create', $options['challenge']);
    $clientDataHash = hash('sha256', Base64UrlSafe::decodeNoPadding($clientDataJSON), true);
    $signature = WebAuthnTestKit::sign($kit['key'], $authData.$clientDataHash);
    $attestationObject = WebAuthnTestKit::attestationObject($authData, $signature);

    $register = $this->postJson('/api/v1/auth/passkey/register', [
        'registration_token' => $options['registration_token'],
        'credential' => [
            'id' => WebAuthnTestKit::base64Url($credentialId),
            'raw_id' => WebAuthnTestKit::base64Url($credentialId),
            'type' => 'public-key',
            'response' => [
                'client_data_json' => $clientDataJSON,
                'attestation_object' => $attestationObject,
                'transports' => ['usb'],
            ],
        ],
        'name' => 'YubiKey 5 NFC',
        'transport' => 'usb',
    ])->assertStatus(201)->json('data');

    expect($register['name'])->toBe('YubiKey 5 NFC');

    $list = $this->getJson('/api/v1/auth/authenticators')->assertOk()->json('data');
    expect($list)->toHaveCount(1);
    expect($list[0]['id'])->toBe($register['id']);

    $stored = Authenticator::query()->first();
    expect($stored->credential_data)->not->toBeNull();

    // --- Assertion with the real signature (challenge from /options) ---
    $assertionOptions = $this->getJson('/api/v1/auth/passkey/options')->assertOk()->json();

    $assertAuthData = WebAuthnTestKit::authenticatorData($rpIdHash, 0x05, 1);
    $assertClientData = WebAuthnTestKit::clientDataJSON('webauthn.get', $assertionOptions['challenge']);
    $assertClientDataHash = hash('sha256', Base64UrlSafe::decodeNoPadding($assertClientData), true);
    $assertSignature = WebAuthnTestKit::sign($kit['key'], $assertAuthData.$assertClientDataHash);

    $login = $this->postJson('/api/v1/auth/passkey/verify', [
        'credential' => [
            'id' => WebAuthnTestKit::base64Url($credentialId),
            'raw_id' => WebAuthnTestKit::base64Url($credentialId),
            'type' => 'public-key',
            'response' => [
                'client_data_json' => $assertClientData,
                'authenticator_data' => WebAuthnTestKit::base64Url($assertAuthData),
                'signature' => WebAuthnTestKit::base64Url($assertSignature),
            ],
        ],
    ])->assertOk();

    expect($login->json('token'))->toBeString();
    expect(strlen($login->json('token')))->toBeGreaterThan(20);
    expect($login->json('user.id'))->toBe($user->id);

    // The sign counter was bumped and persisted.
    expect(Authenticator::query()->first()->sign_count)->toBe(1);
});

it('rejects a replayed assertion (challenge is single-use)', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // Register a real credential first.
    $options = $this->getJson('/api/v1/auth/passkey/register-options')->assertOk()->json();
    $kit = WebAuthnTestKit::keyPair();
    $credentialId = random_bytes(32);
    $rpIdHash = hash('sha256', 'localhost', true);
    $authData = WebAuthnTestKit::authenticatorData($rpIdHash, 0x41, 0, $credentialId, WebAuthnTestKit::cosePublicKey($kit['x'], $kit['y']));
    $clientDataJSON = WebAuthnTestKit::clientDataJSON('webauthn.create', $options['challenge']);
    $clientDataHash = hash('sha256', Base64UrlSafe::decodeNoPadding($clientDataJSON), true);
    $attestationObject = WebAuthnTestKit::attestationObject($authData, WebAuthnTestKit::sign($kit['key'], $authData.$clientDataHash));

    $this->postJson('/api/v1/auth/passkey/register', [
        'registration_token' => $options['registration_token'],
        'credential' => [
            'id' => WebAuthnTestKit::base64Url($credentialId),
            'raw_id' => WebAuthnTestKit::base64Url($credentialId),
            'type' => 'public-key',
            'response' => [
                'client_data_json' => $clientDataJSON,
                'attestation_object' => $attestationObject,
            ],
        ],
    ])->assertStatus(201);

    // Build a valid assertion…
    $assertionOptions = $this->getJson('/api/v1/auth/passkey/options')->assertOk()->json();
    $assertAuthData = WebAuthnTestKit::authenticatorData($rpIdHash, 0x05, 1);
    $assertClientData = WebAuthnTestKit::clientDataJSON('webauthn.get', $assertionOptions['challenge']);
    $assertClientDataHash = hash('sha256', Base64UrlSafe::decodeNoPadding($assertClientData), true);
    $assertSignature = WebAuthnTestKit::sign($kit['key'], $assertAuthData.$assertClientDataHash);
    $credential = [
        'id' => WebAuthnTestKit::base64Url($credentialId),
        'raw_id' => WebAuthnTestKit::base64Url($credentialId),
        'type' => 'public-key',
        'response' => [
            'client_data_json' => $assertClientData,
            'authenticator_data' => WebAuthnTestKit::base64Url($assertAuthData),
            'signature' => WebAuthnTestKit::base64Url($assertSignature),
        ],
    ];

    // First use succeeds…
    $this->postJson('/api/v1/auth/passkey/verify', ['credential' => $credential])->assertOk();

    // …replaying the exact same assertion fails: the challenge was consumed.
    $this->postJson('/api/v1/auth/passkey/verify', ['credential' => $credential])->assertStatus(401);
});

it('rejects a tampered signature', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $options = $this->getJson('/api/v1/auth/passkey/register-options')->assertOk()->json();
    $kit = WebAuthnTestKit::keyPair();
    $credentialId = random_bytes(32);
    $rpIdHash = hash('sha256', 'localhost', true);
    $authData = WebAuthnTestKit::authenticatorData($rpIdHash, 0x41, 0, $credentialId, WebAuthnTestKit::cosePublicKey($kit['x'], $kit['y']));
    $clientDataJSON = WebAuthnTestKit::clientDataJSON('webauthn.create', $options['challenge']);
    $clientDataHash = hash('sha256', Base64UrlSafe::decodeNoPadding($clientDataJSON), true);
    $attestationObject = WebAuthnTestKit::attestationObject($authData, WebAuthnTestKit::sign($kit['key'], $authData.$clientDataHash));

    $this->postJson('/api/v1/auth/passkey/register', [
        'registration_token' => $options['registration_token'],
        'credential' => [
            'id' => WebAuthnTestKit::base64Url($credentialId),
            'raw_id' => WebAuthnTestKit::base64Url($credentialId),
            'type' => 'public-key',
            'response' => [
                'client_data_json' => $clientDataJSON,
                'attestation_object' => $attestationObject,
            ],
        ],
    ])->assertStatus(201);

    $assertionOptions = $this->getJson('/api/v1/auth/passkey/options')->assertOk()->json();
    $assertAuthData = WebAuthnTestKit::authenticatorData($rpIdHash, 0x05, 1);
    $assertClientData = WebAuthnTestKit::clientDataJSON('webauthn.get', $assertionOptions['challenge']);
    $assertClientDataHash = hash('sha256', Base64UrlSafe::decodeNoPadding($assertClientData), true);
    // Sign the wrong data (tampered signature).
    $tamperedSignature = WebAuthnTestKit::sign($kit['key'], $assertAuthData.$assertClientDataHash.'tampered');

    $this->postJson('/api/v1/auth/passkey/verify', [
        'credential' => [
            'id' => WebAuthnTestKit::base64Url($credentialId),
            'raw_id' => WebAuthnTestKit::base64Url($credentialId),
            'type' => 'public-key',
            'response' => [
                'client_data_json' => $assertClientData,
                'authenticator_data' => WebAuthnTestKit::base64Url($assertAuthData),
                'signature' => WebAuthnTestKit::base64Url($tamperedSignature),
            ],
        ],
    ])->assertStatus(401);
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

it('rejects a re-registration of the same credential id', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $options = $this->getJson('/api/v1/auth/passkey/register-options')->assertOk()->json();
    $kit = WebAuthnTestKit::keyPair();
    $credentialId = random_bytes(32);
    $rpIdHash = hash('sha256', 'localhost', true);
    $authData = WebAuthnTestKit::authenticatorData($rpIdHash, 0x41, 0, $credentialId, WebAuthnTestKit::cosePublicKey($kit['x'], $kit['y']));
    $clientDataJSON = WebAuthnTestKit::clientDataJSON('webauthn.create', $options['challenge']);
    $clientDataHash = hash('sha256', Base64UrlSafe::decodeNoPadding($clientDataJSON), true);
    $attestationObject = WebAuthnTestKit::attestationObject($authData, WebAuthnTestKit::sign($kit['key'], $authData.$clientDataHash));
    $payload = [
        'registration_token' => $options['registration_token'],
        'credential' => [
            'id' => WebAuthnTestKit::base64Url($credentialId),
            'raw_id' => WebAuthnTestKit::base64Url($credentialId),
            'type' => 'public-key',
            'response' => [
                'client_data_json' => $clientDataJSON,
                'attestation_object' => $attestationObject,
            ],
        ],
    ];

    $this->postJson('/api/v1/auth/passkey/register', $payload)->assertStatus(201);

    // A fresh challenge, same credential id → 409.
    $options2 = $this->getJson('/api/v1/auth/passkey/register-options')->assertOk()->json();
    $clientDataJSON2 = WebAuthnTestKit::clientDataJSON('webauthn.create', $options2['challenge']);
    $clientDataHash2 = hash('sha256', Base64UrlSafe::decodeNoPadding($clientDataJSON2), true);
    $authData2 = WebAuthnTestKit::authenticatorData($rpIdHash, 0x41, 0, $credentialId, WebAuthnTestKit::cosePublicKey($kit['x'], $kit['y']));
    $attestationObject2 = WebAuthnTestKit::attestationObject($authData2, WebAuthnTestKit::sign($kit['key'], $authData2.$clientDataHash2));

    $this->postJson('/api/v1/auth/passkey/register', [
        'registration_token' => $options2['registration_token'],
        'credential' => [
            'id' => WebAuthnTestKit::base64Url($credentialId),
            'raw_id' => WebAuthnTestKit::base64Url($credentialId),
            'type' => 'public-key',
            'response' => [
                'client_data_json' => $clientDataJSON2,
                'attestation_object' => $attestationObject2,
            ],
        ],
    ])->assertStatus(409);
});

it('updates the adaptive security level', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/auth/security-level', ['security_level' => 'critical'])
        ->assertOk()
        ->assertJsonPath('data.security_level', 'critical');

    expect($user->fresh()->security_level)->toBe('critical');
});
