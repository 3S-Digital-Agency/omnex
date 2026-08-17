<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\Support\WebAuthnTestKit;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
});

it('starts a cross-device pairing with a QR payload', function () {
    $response = $this->postJson('/api/v1/auth/cross-device/start');

    $response->assertOk()
        ->assertJsonStructure(['pairing_code', 'challenge', 'rp_id', 'timeout', 'expires_in', 'qr_payload']);

    expect(strlen($response->json('pairing_code')))->toBe(8);
    expect($response->json('qr_payload'))->toContain('omnex://cross-device?code=');
});

it('approves a sandbox pairing and signs the user in', function () {
    $user = User::factory()->create(['email' => 'owner@omnex.cloud']);

    $start = $this->postJson('/api/v1/auth/cross-device/start')->assertOk()->json();

    $approve = $this->postJson('/api/v1/auth/cross-device/approve', [
        'pairing_code' => $start['pairing_code'],
        'device' => 'iphone',
        'method' => 'face_id',
    ])->assertOk();

    expect($approve->json('token'))->toBeString();
    expect($approve->json('user.id'))->toBe($user->id);
});

it('rejects an unknown pairing code', function () {
    $this->postJson('/api/v1/auth/cross-device/approve', [
        'pairing_code' => 'NOPE0000',
        'device' => 'iphone',
    ])->assertStatus(410);
});

it('rejects a replayed pairing code (single-use)', function () {
    User::factory()->create(['email' => 'owner@omnex.cloud']);

    $start = $this->postJson('/api/v1/auth/cross-device/start')->assertOk()->json();
    $payload = [
        'pairing_code' => $start['pairing_code'],
        'device' => 'iphone',
        'method' => 'touch_id',
    ];

    $this->postJson('/api/v1/auth/cross-device/approve', $payload)->assertOk();
    $this->postJson('/api/v1/auth/cross-device/approve', $payload)->assertStatus(410);
});

it('approves a pairing with a real WebAuthn assertion from the phone', function () {
    $user = User::factory()->create();

    // Register the phone's credential on the account (same ceremony as the
    // authenticator management flow).
    $registerOptions = $this->actingAs($user)->getJson('/api/v1/auth/passkey/register-options')->assertOk()->json();
    $kit = WebAuthnTestKit::keyPair();
    $credentialId = random_bytes(32);
    $rpIdHash = hash('sha256', 'localhost', true);
    $authData = WebAuthnTestKit::authenticatorData($rpIdHash, 0x41, 0, $credentialId, WebAuthnTestKit::cosePublicKey($kit['x'], $kit['y']));
    $clientDataJSON = WebAuthnTestKit::clientDataJSON('webauthn.create', $registerOptions['challenge']);
    $clientDataHash = hash('sha256', Base64UrlSafe::decodeNoPadding($clientDataJSON), true);
    $attestationObject = WebAuthnTestKit::attestationObject($authData, WebAuthnTestKit::sign($kit['key'], $authData.$clientDataHash));

    $this->postJson('/api/v1/auth/passkey/register', [
        'registration_token' => $registerOptions['registration_token'],
        'credential' => [
            'id' => WebAuthnTestKit::base64Url($credentialId),
            'raw_id' => WebAuthnTestKit::base64Url($credentialId),
            'type' => 'public-key',
            'response' => [
                'client_data_json' => $clientDataJSON,
                'attestation_object' => $attestationObject,
            ],
        ],
        'name' => 'iPhone Face ID',
        'transport' => 'internal',
    ])->assertStatus(201);

    // The desktop starts a pairing; the phone signs its challenge.
    $start = $this->postJson('/api/v1/auth/cross-device/start')->assertOk()->json();
    $assertAuthData = WebAuthnTestKit::authenticatorData($rpIdHash, 0x05, 1);
    $assertClientData = WebAuthnTestKit::clientDataJSON('webauthn.get', $start['challenge']);
    $assertClientDataHash = hash('sha256', Base64UrlSafe::decodeNoPadding($assertClientData), true);
    $assertSignature = WebAuthnTestKit::sign($kit['key'], $assertAuthData.$assertClientDataHash);

    $approve = $this->postJson('/api/v1/auth/cross-device/approve', [
        'pairing_code' => $start['pairing_code'],
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
        'device' => 'iphone',
        'method' => 'face_id',
    ])->assertOk();

    expect($approve->json('user.id'))->toBe($user->id);
});

it('rejects a pairing with a forged assertion', function () {
    $user = User::factory()->create();
    $kit = WebAuthnTestKit::keyPair();
    $credentialId = random_bytes(32);

    // No authenticator registered for this credential — the assertion cannot verify.
    $start = $this->postJson('/api/v1/auth/cross-device/start')->assertOk()->json();
    $rpIdHash = hash('sha256', 'localhost', true);
    $assertAuthData = WebAuthnTestKit::authenticatorData($rpIdHash, 0x05, 1);
    $assertClientData = WebAuthnTestKit::clientDataJSON('webauthn.get', $start['challenge']);
    $assertClientDataHash = hash('sha256', Base64UrlSafe::decodeNoPadding($assertClientData), true);
    $assertSignature = WebAuthnTestKit::sign($kit['key'], $assertAuthData.$assertClientDataHash);

    $this->postJson('/api/v1/auth/cross-device/approve', [
        'pairing_code' => $start['pairing_code'],
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
        'device' => 'iphone',
    ])->assertStatus(401);
});
