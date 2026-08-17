<?php

use App\Models\User;
use App\Models\UserDevice;
use App\Notifications\NewDeviceSignIn;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\Support\WebAuthnTestKit;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    Notification::fake();
});

it('requires verification on the first sign-in from an unknown device', function () {
    $user = User::factory()->create(['email' => 'owner@omnex.cloud']);

    // Sandbox cross-device pairing from a brand-new device.
    $start = $this->postJson('/api/v1/auth/cross-device/start')->assertOk()->json();
    $response = $this->postJson('/api/v1/auth/cross-device/approve', [
        'pairing_code' => $start['pairing_code'],
        'device' => 'iphone',
        'method' => 'face_id',
        'device_id' => 'device-alpha',
    ])->assertOk();

    expect($response->json('requires_device_verification'))->toBeTrue();
    expect($response->json('verification_token'))->toBeString();
    expect($response->json('token'))->toBeNull();

    Notification::assertSentOnDemand(NewDeviceSignIn::class);
});

it('completes verification with the e-mailed code and remembers the device', function () {
    $user = User::factory()->create(['email' => 'owner@omnex.cloud']);

    $start = $this->postJson('/api/v1/auth/cross-device/start')->assertOk()->json();
    $challenge = $this->postJson('/api/v1/auth/cross-device/approve', [
        'pairing_code' => $start['pairing_code'],
        'device' => 'android',
        'method' => 'fingerprint',
        'device_id' => 'device-alpha',
    ])->assertOk()->json();

    $sent = Notification::sent(new AnonymousNotifiable, NewDeviceSignIn::class)->first();
    $code = $sent->code;

    $verify = $this->postJson('/api/v1/auth/device/verify', [
        'verification_token' => $challenge['verification_token'],
        'code' => $code,
    ])->assertOk();

    expect($verify->json('token'))->toBeString();
    expect($verify->json('user.id'))->toBe($user->id);

    // The device is now known: a later sign-in skips verification.
    $start2 = $this->postJson('/api/v1/auth/cross-device/start')->assertOk()->json();
    $response = $this->postJson('/api/v1/auth/cross-device/approve', [
        'pairing_code' => $start2['pairing_code'],
        'device' => 'android',
        'method' => 'fingerprint',
        'device_id' => 'device-alpha',
    ])->assertOk();

    expect($response->json('requires_device_verification'))->toBeNull();
    expect($response->json('token'))->toBeString();
});

it('rejects a wrong verification code', function () {
    $user = User::factory()->create(['email' => 'owner@omnex.cloud']);

    $start = $this->postJson('/api/v1/auth/cross-device/start')->assertOk()->json();
    $challenge = $this->postJson('/api/v1/auth/cross-device/approve', [
        'pairing_code' => $start['pairing_code'],
        'device' => 'iphone',
        'device_id' => 'device-beta',
    ])->assertOk()->json();

    $this->postJson('/api/v1/auth/device/verify', [
        'verification_token' => $challenge['verification_token'],
        'code' => '000000',
    ])->assertStatus(422);

    $device = UserDevice::query()->first();
    expect($device->verified_at)->toBeNull();
});

it('rejects a replayed verification token (single-use)', function () {
    $user = User::factory()->create(['email' => 'owner@omnex.cloud']);

    $start = $this->postJson('/api/v1/auth/cross-device/start')->assertOk()->json();
    $challenge = $this->postJson('/api/v1/auth/cross-device/approve', [
        'pairing_code' => $start['pairing_code'],
        'device' => 'iphone',
        'device_id' => 'device-gamma',
    ])->assertOk()->json();

    $sent = Notification::sent(new AnonymousNotifiable, NewDeviceSignIn::class)->first();
    $code = $sent->code;

    $payload = [
        'verification_token' => $challenge['verification_token'],
        'code' => $code,
    ];

    $this->postJson('/api/v1/auth/device/verify', $payload)->assertOk();
    $this->postJson('/api/v1/auth/device/verify', $payload)->assertStatus(410);
});

it('requires verification on a first passkey sign-in from an unknown device', function () {
    $user = User::factory()->create();

    // Register a credential on the account first.
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
    ])->assertStatus(201);

    // First sign-in from an unknown device → verification required.
    $assertionOptions = $this->getJson('/api/v1/auth/passkey/options')->assertOk()->json();
    $assertAuthData = WebAuthnTestKit::authenticatorData($rpIdHash, 0x05, 1);
    $assertClientData = WebAuthnTestKit::clientDataJSON('webauthn.get', $assertionOptions['challenge']);
    $assertClientDataHash = hash('sha256', Base64UrlSafe::decodeNoPadding($assertClientData), true);
    $assertSignature = WebAuthnTestKit::sign($kit['key'], $assertAuthData.$assertClientDataHash);

    $response = $this->postJson('/api/v1/auth/passkey/verify', [
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
        'device_id' => 'device-iphone',
        'platform' => 'iphone',
    ])->assertOk();

    expect($response->json('requires_device_verification'))->toBeTrue();
    expect($response->json('token'))->toBeNull();

    // Complete the e-mailed verification first.
    $sent = Notification::sent(new AnonymousNotifiable, NewDeviceSignIn::class)->first();
    $this->postJson('/api/v1/auth/device/verify', [
        'verification_token' => $response->json('verification_token'),
        'code' => $sent->code,
    ])->assertOk();

    // Same device, verified → direct session.
    $assertionOptions2 = $this->getJson('/api/v1/auth/passkey/options')->assertOk()->json();
    $assertAuthData2 = WebAuthnTestKit::authenticatorData($rpIdHash, 0x05, 2);
    $assertClientData2 = WebAuthnTestKit::clientDataJSON('webauthn.get', $assertionOptions2['challenge']);
    $assertClientDataHash2 = hash('sha256', Base64UrlSafe::decodeNoPadding($assertClientData2), true);
    $assertSignature2 = WebAuthnTestKit::sign($kit['key'], $assertAuthData2.$assertClientDataHash2);

    $response2 = $this->postJson('/api/v1/auth/passkey/verify', [
        'credential' => [
            'id' => WebAuthnTestKit::base64Url($credentialId),
            'raw_id' => WebAuthnTestKit::base64Url($credentialId),
            'type' => 'public-key',
            'response' => [
                'client_data_json' => $assertClientData2,
                'authenticator_data' => WebAuthnTestKit::base64Url($assertAuthData2),
                'signature' => WebAuthnTestKit::base64Url($assertSignature2),
            ],
        ],
        'device_id' => 'device-iphone',
        'platform' => 'iphone',
    ])->assertOk();

    expect($response2->json('requires_device_verification'))->toBeNull();
    expect($response2->json('token'))->toBeString();
});
