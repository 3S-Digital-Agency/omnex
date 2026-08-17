<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Models\UserDevice;
use App\Notifications\NewDeviceSignIn;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Unknown-device detection for passwordless sign-in: when a new iPhone /
 * Android phone / passkey tries to authenticate, the user is notified by
 * e-mail and must enter a one-time 6-digit code before the session is issued.
 *
 * A device is "known" once it has been verified. The verification challenge
 * is single-use, short-lived and bound to the user + device, so a leaked or
 * replayed code cannot be re-used.
 */
final class DeviceVerificationService
{
    private const CODE_TTL = 600; // 10 minutes

    /**
     * Returns the device record (creating it if this is the first sighting).
     */
    public function touch(User $user, string $deviceId, ?string $platform = null, ?string $method = null): UserDevice
    {
        $device = UserDevice::query()->firstOrNew(['user_id' => $user->id, 'device_id' => $deviceId]);

        if ($device->exists) {
            $device->last_seen_at = now();
            if ($platform !== null) {
                $device->platform = $platform;
            }
            if ($method !== null) {
                $device->method = $method;
            }
            $device->save();

            return $device;
        }

        $device->platform = $platform;
        $device->method = $method;
        $device->first_seen_at = now();
        $device->last_seen_at = now();
        $device->save();

        return $device;
    }

    /**
     * True when the device has already been verified by this user.
     */
    public function isKnown(UserDevice $device): bool
    {
        return $device->verified_at !== null;
    }

    /**
     * Starts a verification: caches a single-use code, e-mails it to the user
     * and returns the verification token to echo back on the next step.
     */
    public function beginVerification(User $user, UserDevice $device): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $token = Str::random(64);

        Cache::put($this->codeCacheKey($token), [
            'user_id' => $user->id,
            'device_id' => $device->device_id,
            'code' => $code,
        ], self::CODE_TTL);

        Notification::route('mail', $user->email)->notify(new NewDeviceSignIn($code, $user->name));

        return $token;
    }

    /**
     * Resolves the pending challenge (consuming it), or null when unknown /
     * expired. Returns the stored payload (user id, device id, code).
     *
     * @return array{user_id: int, device_id: string, code: string}|null
     */
    public function resolveChallenge(string $token): ?array
    {
        $challenge = Cache::pull($this->codeCacheKey($token));

        return is_array($challenge) ? $challenge : null;
    }

    /**
     * Completes the verification: checks the code, marks the device as known
     * and returns the user. The challenge was already consumed by
     * resolveChallenge() — replay is impossible.
     *
     * @param  array{user_id: int, device_id: string, code: string}  $challenge
     */
    public function complete(array $challenge, string $code): User
    {
        if (! hash_equals((string) $challenge['code'], $code)) {
            throw new DomainException('Invalid verification code.');
        }

        $user = User::query()->find($challenge['user_id']);
        if ($user === null) {
            throw new DomainException('Account not found.');
        }

        $device = UserDevice::query()->firstOrNew([
            'user_id' => $user->id,
            'device_id' => $challenge['device_id'],
        ]);
        $device->verified_at = now();
        $device->first_seen_at ??= now();
        $device->last_seen_at = now();
        $device->save();

        return $user;
    }

    /**
     * Stable device fingerprint: derived from a client-provided random id
     * (persisted in localStorage) rather than a fragile hardware fingerprint.
     */
    public static function fingerprint(string $clientId): string
    {
        return hash('sha256', 'omnex-device:'.$clientId);
    }

    private function codeCacheKey(string $token): string
    {
        return 'device-verify:'.$token;
    }
}
