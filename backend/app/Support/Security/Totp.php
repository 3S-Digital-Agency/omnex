<?php

namespace App\Support\Security;

/**
 * Minimal RFC 6238 TOTP implementation (SHA-1, 6 digits, 30s period) plus
 * RFC 4648 base32. Kept dependency-free so MFA has no third-party supply-chain
 * surface.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function verify(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $decoded = self::base32Decode($secret);
        $counter = (int) floor(($timestamp ?? time()) / 30);

        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::totp($decoded, $counter + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function otpauthUri(string $secret, string $label, string $issuer): string
    {
        $encodedIssuer = rawurlencode($issuer);
        $encodedLabel = rawurlencode($issuer.':'.$label);

        return "otpauth://totp/{$encodedLabel}?secret={$secret}&issuer={$encodedIssuer}&algorithm=SHA1&digits=6&period=30";
    }

    private static function totp(string $key, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('N2', 0, $counter), $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % 1000000;

        return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $binary = '';
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $binary .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($binary, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $encoded;
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(rtrim($secret, '='));

        $binary = '';
        for ($i = 0, $n = strlen($secret); $i < $n; $i++) {
            $position = strpos(self::ALPHABET, $secret[$i]);
            if ($position === false) {
                continue;
            }
            $binary .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) < 8) {
                break;
            }
            $decoded .= chr(bindec($byte));
        }

        return $decoded;
    }
}
