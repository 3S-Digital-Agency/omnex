<?php

declare(strict_types=1);

namespace Tests\Support;

use CBOR\ByteStringObject;
use CBOR\Encoder;
use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use ParagonIE\ConstantTime\Base64UrlSafe;

/**
 * Generates real WebAuthn attestations and assertions for the test suite:
 * a fresh ES256 (P-256) keypair, a COSE credential public key, a packed
 * self-attestation object and a signed assertion — all cryptographically
 * valid so the full verification pipeline (web-auth/webauthn-lib) runs for
 * real instead of being stubbed.
 */
final class WebAuthnTestKit
{
    /**
     * @return array{key: OpenSSLAsymmetricKey, x: string, y: string}
     */
    public static function keyPair(): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
            // Workaround for PHP/OpenSSL 3.6+ requiring private_key_bits for
            // EC keys (php-src#21083); the curve still defines the real size.
            'private_key_bits' => 384,
        ]);
        if ($key === false) {
            throw new InvalidArgumentException('Unable to generate an EC keypair.');
        }
        $details = openssl_pkey_get_details($key);
        if ($details === false || ! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new InvalidArgumentException('Unable to read EC key details.');
        }

        // Depending on the PHP/OpenSSL build, x/y are returned either as hex
        // (64 chars) or raw binary (32 bytes) — normalize to raw bytes.
        $coords = static function (string $value): string {
            if (strlen($value) === 64 && ctype_xdigit($value)) {
                $raw = hex2bin($value);
                if ($raw !== false) {
                    return $raw;
                }
            }

            return $value;
        };

        return [
            'key' => $key,
            'x' => $coords($details['ec']['x']),
            'y' => $coords($details['ec']['y']),
        ];
    }

    /**
     * COSE EC2 public key (alg ES256 / curve P-256) as CBOR bytes.
     */
    public static function cosePublicKey(string $x, string $y): string
    {
        $encoder = new Encoder;

        return (string) $encoder->encode([
            1 => 2, // kty: EC2
            3 => -7, // alg: ES256
            -1 => 1, // crv: P-256
            -2 => ByteStringObject::create($x),
            -3 => ByteStringObject::create($y),
        ]);
    }

    /**
     * WebAuthn authenticator data.
     *
     * @param  string  $rpIdHash  32-byte SHA-256 of the RP ID
     * @param  int  $flags  flags byte (UP=0x01, UV=0x04, AT=0x40)
     */
    public static function authenticatorData(
        string $rpIdHash,
        int $flags,
        int $signCount,
        ?string $credentialId = null,
        ?string $cosePublicKey = null
    ): string {
        $authData = $rpIdHash.chr($flags).pack('N', $signCount);
        if (($flags & 0x40) !== 0) { // AT flag → attested credential data
            $aaguid = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";
            $authData .= $aaguid.pack('n', strlen($credentialId ?? '')).($credentialId ?? '').($cosePublicKey ?? '');
        }

        return $authData;
    }

    /**
     * clientDataJSON for the given ceremony type and challenge (base64url),
     * base64url-encoded as a real browser would send it.
     */
    public static function clientDataJSON(string $type, string $challengeB64Url, string $origin = 'http://localhost:5173'): string
    {
        $json = json_encode([
            'type' => $type,
            'challenge' => $challengeB64Url,
            'origin' => $origin,
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR);

        return Base64UrlSafe::encodeUnpadded($json);
    }

    /**
     * ES256 signature (DER, as openssl produces — the library converts it).
     */
    public static function sign(OpenSSLAsymmetricKey $key, string $data): string
    {
        openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);

        return $signature;
    }

    /**
     * Packed attestation object (self-attestation, no x5c) as base64url CBOR.
     */
    public static function attestationObject(string $authData, string $signature): string
    {
        $cbor = (string) (new Encoder)->encode([
            'fmt' => 'packed',
            'attStmt' => [
                'alg' => -7,
                'sig' => ByteStringObject::create($signature),
            ],
            'authData' => ByteStringObject::create($authData),
        ]);

        return Base64UrlSafe::encodeUnpadded($cbor);
    }

    public static function base64Url(string $raw): string
    {
        return Base64UrlSafe::encodeUnpadded($raw);
    }
}
