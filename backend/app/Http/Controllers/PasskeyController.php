<?php

namespace App\Http\Controllers;

use App\Models\Authenticator;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AuthSessionResponse;
use App\Support\Auth\DeviceVerificationService;
use App\Support\Auth\WebAuthnService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasskeyController extends Controller
{
    public function __construct(private readonly WebAuthnService $webauthn) {}

    /**
     * Public — issue a WebAuthn assertion challenge for passkey sign-in.
     */
    public function options(Request $request): JsonResponse
    {
        return response()->json($this->webauthn->assertionOptions($request));
    }

    /**
     * Public — verify a WebAuthn assertion and sign the user in (passkeys,
     * YubiKey, Touch ID / Face ID, Windows Hello — all through WebAuthn).
     * The challenge is single-use (anti-replay), the signature is verified
     * against the stored credential public key and the sign counter must be
     * strictly increasing.
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'credential.id' => ['required', 'string'],
            'credential.raw_id' => ['nullable', 'string'],
            'credential.type' => ['nullable', 'string'],
            'credential.response.client_data_json' => ['required', 'string'],
            'credential.response.authenticator_data' => ['nullable', 'string'],
            'credential.response.signature' => ['nullable', 'string'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $user = $this->webauthn->verifyAssertion($request, $data['credential']);
        } catch (DomainException $e) {
            AuditLogger::record('auth.passkey_failed', 'authenticator', null, null, null, 'failure');

            return response()->json(['message' => $e->getMessage()], 401);
        }

        AuditLogger::record('auth.passkey_authenticated', 'authenticator');

        // Unknown-device check: a brand-new passkey/phone must confirm the
        // sign-in with an e-mailed code before a session is issued.
        $deviceCheck = $this->deviceCheck($request, $user, $data['device_id'] ?? null, $data['platform'] ?? null);
        if ($deviceCheck !== null) {
            return $deviceCheck;
        }

        return response()->json(AuthSessionResponse::make($user, 'omnex-passkey'));
    }

    /**
     * Public — verify the 6-digit code sent by e-mail for an unknown device
     * and complete the sign-in.
     */
    public function verifyDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'verification_token' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $verification = app(DeviceVerificationService::class);
        $resolved = $verification->resolveChallenge($data['verification_token']);
        if ($resolved === null) {
            return response()->json(['message' => 'Invalid or expired verification code.'], 410);
        }

        try {
            $user = $verification->complete($resolved, $data['code']);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        AuditLogger::record('auth.device_verified', 'user', $user->id, null, [
            'device_id' => $resolved['device_id'],
        ]);

        return response()->json(AuthSessionResponse::make($user, 'omnex-device-verified'));
    }

    private function deviceCheck(Request $request, User $user, ?string $deviceId, ?string $platform): ?JsonResponse
    {
        if ($deviceId === null || $deviceId === '') {
            return null;
        }

        $verification = app(DeviceVerificationService::class);
        $device = $verification->touch($user, DeviceVerificationService::fingerprint($deviceId), $platform, 'passkey');

        if ($verification->isKnown($device)) {
            return null;
        }

        $token = $verification->beginVerification($user, $device);

        AuditLogger::record('auth.unknown_device_detected', 'user', $user->id, null, [
            'device_id' => $device->device_id,
        ]);

        return response()->json([
            'requires_device_verification' => true,
            'verification_token' => $token,
            'expires_in' => 600,
        ]);
    }

    /**
     * Authenticated — list the user's registered authenticators.
     */
    public function index(Request $request): JsonResponse
    {
        $authenticators = $request->user()->authenticators()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn (Authenticator $authenticator) => [
                'id' => (string) $authenticator->id,
                'name' => $authenticator->name,
                'transport' => $authenticator->transport,
                'last_used_at' => $authenticator->last_used_at?->toIso8601String(),
                'created_at' => $authenticator->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $authenticators->all()]);
    }

    /**
     * Authenticated — issue a WebAuthn registration challenge so the browser
     * can create a new passkey / register a YubiKey or biometric device.
     */
    public function registerOptions(Request $request): JsonResponse
    {
        return response()->json($this->webauthn->creationOptions($request, $request->user()));
    }

    /**
     * Authenticated — verify the WebAuthn attestation (signature, origin,
     * challenge, clientDataJSON) and store the credential public key.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registration_token' => ['required', 'string'],
            'credential.id' => ['required', 'string'],
            'credential.raw_id' => ['nullable', 'string'],
            'credential.type' => ['nullable', 'string'],
            'credential.response.client_data_json' => ['required', 'string'],
            'credential.response.attestation_object' => ['required', 'string'],
            'credential.response.transports' => ['nullable', 'array'],
            'name' => ['nullable', 'string', 'max:100'],
            'transport' => ['nullable', 'string', 'max:20'],
        ]);

        try {
            $authenticator = $this->webauthn->verifyAttestation(
                $request,
                $request->user(),
                $data['registration_token'],
                $data['credential'],
                $data['name'] ?? null,
                $data['transport'] ?? null,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        AuditLogger::record('auth.authenticator_registered', 'authenticator', $authenticator->id);

        return response()->json([
            'data' => [
                'id' => (string) $authenticator->id,
                'name' => $authenticator->name,
                'transport' => $authenticator->transport,
                'last_used_at' => $authenticator->last_used_at?->toIso8601String(),
                'created_at' => $authenticator->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Authenticated — revoke an authenticator (lost key, replaced device…).
     */
    public function destroy(Request $request, Authenticator $authenticator): JsonResponse
    {
        if ($authenticator->user_id !== $request->user()->id) {
            abort(403);
        }

        $authenticator->delete();
        AuditLogger::record('auth.authenticator_revoked', 'authenticator', $authenticator->id);

        return response()->json(['message' => 'Authenticator revoked.']);
    }

    /**
     * Authenticated — update the security level (standard / enhanced / critical).
     */
    public function updateSecurityLevel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'security_level' => ['required', 'in:standard,enhanced,critical'],
        ]);

        $user = $request->user();
        $user->security_level = $data['security_level'];
        $user->save();
        AuditLogger::record('auth.security_level_changed', 'user', $user->id, null, ['level' => $data['security_level']]);

        return response()->json(['data' => ['security_level' => $user->security_level]]);
    }
}
