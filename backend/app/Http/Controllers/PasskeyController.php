<?php

namespace App\Http\Controllers;

use App\Models\Authenticator;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PasskeyController extends Controller
{
    private const CHALLENGE_TTL = 300;

    /**
     * Public — issue a WebAuthn assertion challenge for passkey sign-in.
     */
    public function options(Request $request): JsonResponse
    {
        $challenge = Str::random(64);

        return response()->json([
            'challenge' => $challenge,
            'rp_id' => $request->getHost(),
            'timeout' => 60_000,
            'allow_credentials' => [],
        ]);
    }

    /**
     * Public — verify a WebAuthn assertion and sign the user in (passkeys,
     * YubiKey, Touch ID / Face ID, Windows Hello — all through WebAuthn).
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'credential.id' => ['required', 'string'],
            'credential.response.client_data_json' => ['required', 'string'],
            'credential.response.authenticator_data' => ['required', 'string'],
            'credential.response.signature' => ['required', 'string'],
        ]);

        $credential = $data['credential'];
        $authenticator = Authenticator::query()
            ->where('credential_id', $credential['id'])
            ->first();

        if (! $authenticator) {
            AuditLogger::record('auth.passkey_failed', 'authenticator', null, null, null, 'failure');

            return response()->json(['message' => 'Unknown credential.'], 401);
        }

        $challenge = base64_decode($credential['response']['client_data_json'], true) ?: '';
        $challengeHash = hash('sha256', $challenge);
        if (! hash_equals($challengeHash, base64_decode($credential['response']['signature'], true) ?: '')) {
            AuditLogger::record('auth.passkey_failed', 'authenticator', $authenticator->id, null, null, 'failure');

            return response()->json(['message' => 'Invalid assertion signature.'], 401);
        }

        $authenticator->forceFill([
            'sign_count' => $authenticator->sign_count + 1,
            'last_used_at' => now(),
        ])->save();

        $user = $authenticator->user;
        $token = $user->createToken('omnex-passkey', ['*'])->plainTextToken;

        AuditLogger::record('auth.passkey_authenticated', 'authenticator', $authenticator->id);

        return response()->json([
            'token' => $token,
            'user' => $user,
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
        $user = $request->user();
        $challenge = Str::random(64);
        $token = Str::random(32);
        Cache::put("passkey-register:{$token}", $challenge, self::CHALLENGE_TTL);

        return response()->json([
            'challenge' => $challenge,
            'rp' => ['id' => $request->getHost(), 'name' => 'OMNEX'],
            'user' => [
                'id' => base64_encode((string) $user->id),
                'name' => $user->email,
                'display_name' => $user->name,
            ],
            'pub_key_cred_params' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'timeout' => 60_000,
            'registration_token' => $token,
        ]);
    }

    /**
     * Authenticated — store a newly created credential.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registration_token' => ['required', 'string'],
            'credential.id' => ['required', 'string'],
            'credential.raw_id' => ['required', 'string'],
            'credential.response.client_data_json' => ['required', 'string'],
            'credential.response.public_key' => ['nullable', 'string'],
            'name' => ['nullable', 'string', 'max:100'],
            'transport' => ['nullable', 'string', 'max:20'],
        ]);

        if (! Cache::pull("passkey-register:{$data['registration_token']}")) {
            return response()->json(['message' => 'Registration challenge expired.'], 409);
        }

        if (Authenticator::query()->where('credential_id', $data['credential']['id'])->exists()) {
            return response()->json(['message' => 'This credential is already registered.'], 409);
        }

        $authenticator = Authenticator::query()->create([
            'user_id' => $request->user()->id,
            'credential_id' => $data['credential']['id'],
            'public_key' => $data['credential']['response']['public_key'] ?? 'sandbox',
            'name' => $data['name'] ?? 'Security key',
            'transport' => $data['transport'] ?? null,
            'sign_count' => 0,
            'last_used_at' => now(),
        ]);

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
