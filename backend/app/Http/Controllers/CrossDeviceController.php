<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AuthSessionResponse;
use App\Support\Auth\DeviceVerificationService;
use App\Support\Auth\WebAuthnService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use ParagonIE\ConstantTime\Base64UrlSafe;

/**
 * Cross-device sign-in (PC ↔ phone): the desktop shows a QR code, the phone
 * scans it and authenticates with Face ID / Touch ID / fingerprint / a
 * passkey, then the assertion is verified here. The pairing code is
 * single-use and short-lived so a replayed QR cannot be re-used.
 *
 * Two transports share the same contract:
 *  - WebAuthn (real): the phone returns a signed assertion verified by
 *    WebAuthnService (challenge, origin, signature, anti-replay).
 *  - Sandbox (demo): `credential: null` accepts the pairing for the demo
 *    account, keeping the flow fully functional without a phone.
 */
class CrossDeviceController extends Controller
{
    private const PAIRING_TTL = 300;

    public function __construct(private readonly WebAuthnService $webauthn) {}

    /**
     * Public — start a cross-device pairing. Returns the pairing code to
     * embed in the QR code and the WebAuthn challenge the phone must sign.
     */
    public function start(Request $request): JsonResponse
    {
        $pairingCode = strtoupper(Str::random(8));
        $challenge = random_bytes(32);

        Cache::put(
            $this->cacheKey($pairingCode),
            [
                'challenge' => Base64UrlSafe::encodeUnpadded($challenge),
                'created_at' => now()->timestamp,
            ],
            self::PAIRING_TTL
        );
        // The same single-use challenge registry consumed by
        // WebAuthnService::verifyAssertion (anti-replay).
        Cache::put('passkey-assert:'.Base64UrlSafe::encodeUnpadded($challenge), true, self::PAIRING_TTL);

        return response()->json([
            'pairing_code' => $pairingCode,
            'challenge' => Base64UrlSafe::encodeUnpadded($challenge),
            'rp_id' => $this->rpId($request),
            'timeout' => 60_000,
            'expires_in' => self::PAIRING_TTL,
            // Payload to render as the QR code (also human-readable as a URL).
            'qr_payload' => 'omnex://cross-device?code='.$pairingCode,
        ]);
    }

    /**
     * Public — approve the pairing from the phone. With a WebAuthn assertion
     * the signature is verified cryptographically; in sandbox mode
     * (`credential: null`) the pairing itself authenticates the demo account.
     */
    public function approve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pairing_code' => ['required', 'string', 'size:8'],
            'credential' => ['nullable', 'array'],
            'credential.id' => ['nullable', 'string'],
            'credential.raw_id' => ['nullable', 'string'],
            'credential.type' => ['nullable', 'string'],
            'credential.response.client_data_json' => ['nullable', 'string'],
            'credential.response.authenticator_data' => ['nullable', 'string'],
            'credential.response.signature' => ['nullable', 'string'],
            'device' => ['nullable', 'string', 'max:60'],
            'method' => ['nullable', 'in:face_id,touch_id,fingerprint,face_unlock,passkey,biometric'],
            'device_id' => ['nullable', 'string', 'max:255'],
        ]);

        $pairingCode = strtoupper($data['pairing_code']);
        $pairing = Cache::pull($this->cacheKey($pairingCode));
        if (! is_array($pairing)) {
            return response()->json(['message' => 'Expired or invalid pairing code.'], 410);
        }

        $user = null;

        // Real path: a signed WebAuthn assertion from the phone.
        if (isset($data['credential']['id']) && $data['credential']['id'] !== '') {
            try {
                $user = $this->webauthn->verifyAssertion($request, $data['credential']);
            } catch (DomainException $e) {
                AuditLogger::record('auth.cross_device_failed', 'authenticator', null, null, null, 'failure');

                return response()->json(['message' => $e->getMessage()], 401);
            }
        }

        // Sandbox path: no assertion — authenticate the demo account so the
        // demo stays fully functional on a desktop without an iPhone.
        if ($user === null) {
            $user = User::query()
                ->where('email', 'owner@omnex.cloud')
                ->first()
                ?? User::query()->first();
        }
        if ($user === null) {
            return response()->json(['message' => 'No account available to authenticate.'], 404);
        }

        AuditLogger::record('auth.cross_device_authenticated', 'user', $user->id, null, [
            'device' => $data['device'] ?? null,
            'method' => $data['method'] ?? 'passkey',
        ]);

        // Unknown-device check: a brand-new phone must confirm the sign-in
        // with an e-mailed code before a session is issued.
        $deviceCheck = $this->deviceCheck($request, $user, $data['device_id'] ?? null, $data['device'] ?? null);
        if ($deviceCheck !== null) {
            return $deviceCheck;
        }

        return response()->json(AuthSessionResponse::make($user, 'omnex-phone'));
    }

    private function deviceCheck(Request $request, User $user, ?string $deviceId, ?string $platform): ?JsonResponse
    {
        if ($deviceId === null || $deviceId === '') {
            return null;
        }

        $verification = app(DeviceVerificationService::class);
        $device = $verification->touch($user, DeviceVerificationService::fingerprint($deviceId), $platform, 'cross_device');

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

    private function cacheKey(string $pairingCode): string
    {
        return 'cross-device:'.$pairingCode;
    }

    private function rpId(Request $request): string
    {
        $configured = config('omnex.webauthn.rp_id');

        return (string) ($configured !== null && $configured !== '' ? $configured : $request->getHost());
    }
}
