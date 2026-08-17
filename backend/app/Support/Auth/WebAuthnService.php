<?php

namespace App\Support\Auth;

use App\Models\Authenticator;
use App\Models\User;
use Cose\Algorithm\Manager as CoseAlgorithmManager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\FidoU2FAttestationStatementSupport;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AttestationStatement\PackedAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * WebAuthn / FIDO2 orchestration with full cryptographic verification
 * (web-auth/webauthn-lib v5). Replaces the previous placeholder check:
 *
 *  - Registration validates the real attestation statement (signature,
 *    clientDataJSON, origin, challenge) and extracts the credential public
 *    key (COSE/CBOR) into a persisted CredentialRecord.
 *  - Authentication validates the assertion: challenge match, allowed
 *    origins, RP ID hash, user presence/verification flags, the actual
 *    signature over (authenticatorData ‖ clientDataHash), and the sign
 *    counter (anti-replay, strictly-increasing).
 *  - Challenges are single-use: they are consumed from the cache on the
 *    first verification, so a replayed assertion cannot be re-used.
 *
 * Passkeys, YubiKey, YubiKey Bio, Windows Hello, Touch ID / Face ID and all
 * FIDO2 authenticators go through the same flow.
 */
final class WebAuthnService
{
    private const CHALLENGE_TTL = 300;

    public function __construct() {}

    /**
     * Assertion options (public) — issues a one-time challenge for sign-in.
     *
     * @return array{challenge: string, rp_id: string, timeout: int, allow_credentials: array}
     */
    public function assertionOptions(Request $request): array
    {
        $challenge = random_bytes(32);

        Cache::put(
            $this->assertCacheKey($challenge),
            true,
            self::CHALLENGE_TTL
        );

        return [
            'challenge' => Base64UrlSafe::encodeUnpadded($challenge),
            'rp_id' => $this->rpId($request),
            'timeout' => 60_000,
            'allow_credentials' => [],
        ];
    }

    /**
     * Verify a WebAuthn assertion and return the owning user.
     *
     * @param  array<string, mixed>  $data  normalized credential payload
     */
    public function verifyAssertion(Request $request, array $data): User
    {
        $credential = $this->credential($data);
        $response = $credential->response;
        if (! $response instanceof AuthenticatorAssertionResponse) {
            throw new DomainException('Expected a WebAuthn assertion response.');
        }

        // Single-use challenge: consuming it from the cache is the replay guard.
        $challenge = $response->clientDataJSON->challenge;
        if (! Cache::pull($this->assertCacheKey($challenge))) {
            throw new DomainException('Expired or replayed challenge.');
        }

        $authenticator = Authenticator::query()
            ->where('credential_id', $data['id'])
            ->first();

        if (! $authenticator) {
            throw new DomainException('Unknown credential.');
        }

        $record = $this->recordOf($authenticator);

        $options = PublicKeyCredentialRequestOptions::create(
            $challenge,
            $this->rpId($request),
            [],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            60_000
        );

        $validator = AuthenticatorAssertionResponseValidator::create(
            $this->ceremony($request)->requestCeremony()
        );

        try {
            $validator->check(
                $record,
                $response,
                $options,
                $request->getHost(),
                $record->userHandle
            );
        } catch (\Throwable $e) {
            throw new DomainException('Assertion rejected: '.$e->getMessage(), previous: $e);
        }

        $authenticator->forceFill([
            'sign_count' => $record->counter,
            'credential_data' => $this->serializer()->serialize($record, 'json'),
            'last_used_at' => now(),
        ])->save();

        return $authenticator->user;
    }

    /**
     * Creation options (authenticated) — issues a registration ceremony.
     *
     * @return array<string, mixed>
     */
    public function creationOptions(Request $request, User $user): array
    {
        $challenge = random_bytes(32);
        $token = Str::random(32);

        Cache::put("passkey-register:{$token}", Base64UrlSafe::encodeUnpadded($challenge), self::CHALLENGE_TTL);

        return [
            'challenge' => Base64UrlSafe::encodeUnpadded($challenge),
            'rp' => ['id' => $this->rpId($request), 'name' => 'OMNEX'],
            'user' => [
                'id' => Base64UrlSafe::encodeUnpadded((string) $user->id),
                'name' => $user->email,
                'display_name' => $user->name,
            ],
            'pub_key_cred_params' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'timeout' => 60_000,
            'registration_token' => $token,
        ];
    }

    /**
     * Verify a WebAuthn attestation and persist the authenticator.
     *
     * @param  array<string, mixed>  $data  normalized credential payload
     */
    /**
     * Verify a WebAuthn attestation and persist the authenticator.
     *
     * @param  array<string, mixed>  $data  normalized credential payload
     * @param  string|null  $name  user-facing device name
     * @param  string|null  $transport  declared transport (usb/nfc/ble/internal…)
     */
    public function verifyAttestation(
        Request $request,
        User $user,
        string $token,
        array $data,
        ?string $name = null,
        ?string $transport = null
    ): Authenticator {
        $challengeB64 = Cache::pull("passkey-register:{$token}");
        if (! $challengeB64) {
            throw new DomainException('Registration challenge expired.');
        }
        $challenge = Base64UrlSafe::decodeNoPadding($challengeB64);

        $credential = $this->credential($data);
        $response = $credential->response;
        if (! $response instanceof AuthenticatorAttestationResponse) {
            throw new DomainException('Expected a WebAuthn attestation response.');
        }

        if (Authenticator::query()->where('credential_id', $data['id'])->exists()) {
            throw new DomainException('This credential is already registered.');
        }

        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create('OMNEX', $this->rpId($request)),
            PublicKeyCredentialUserEntity::create($user->email, (string) $user->id, $user->name),
            $challenge,
            [
                PublicKeyCredentialParameters::createPk(-7),
                PublicKeyCredentialParameters::createPk(-257),
            ],
            AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED
            )
        );

        $validator = AuthenticatorAttestationResponseValidator::create(
            $this->ceremony($request)->creationCeremony()
        );

        try {
            $record = $validator->check($response, $options, $request->getHost());
        } catch (\Throwable $e) {
            throw new DomainException('Attestation rejected: '.$e->getMessage(), previous: $e);
        }

        return Authenticator::query()->create([
            'user_id' => $user->id,
            'credential_id' => $data['id'],
            'public_key' => Base64UrlSafe::encodeUnpadded($record->credentialPublicKey),
            'credential_data' => $this->serializer()->serialize($record, 'json'),
            'sign_count' => $record->counter,
            'name' => $name ?? 'Security key',
            'transport' => $transport ?? ($response->transports[0] ?? null),
            'last_used_at' => now(),
        ]);
    }

    /**
     * Denormalize the raw credential payload into a Webauthn PublicKeyCredential,
     * mapping the frontend snake_case contract to the camelCase the library
     * expects ({id, rawId, type, response:{clientDataJSON, …}}).
     *
     * @param  array<string, mixed>  $data
     */
    private function credential(array $data): PublicKeyCredential
    {
        $response = $data['response'] ?? [];

        $mapped = [
            'id' => (string) ($data['id'] ?? ''),
            'rawId' => (string) ($data['raw_id'] ?? $data['id'] ?? ''),
            'type' => (string) ($data['type'] ?? 'public-key'),
            'response' => [
                'clientDataJSON' => (string) ($response['client_data_json'] ?? ''),
            ],
        ];

        if (array_key_exists('attestation_object', $response)) {
            $mapped['response']['attestationObject'] = (string) $response['attestation_object'];
            $mapped['response']['transports'] = $response['transports'] ?? [];
        } else {
            $mapped['response']['authenticatorData'] = (string) ($response['authenticator_data'] ?? '');
            $mapped['response']['signature'] = (string) ($response['signature'] ?? '');
        }

        $json = json_encode($mapped, JSON_THROW_ON_ERROR);

        try {
            return $this->serializer()->deserialize($json, PublicKeyCredential::class, 'json');
        } catch (\Throwable $e) {
            throw new DomainException('Invalid credential payload: '.$e->getMessage(), previous: $e);
        }
    }

    private function recordOf(Authenticator $authenticator): CredentialRecord
    {
        $data = $authenticator->credential_data;
        if (! is_string($data) || $data === '') {
            throw new DomainException('This authenticator has no stored credential.');
        }

        try {
            return $this->serializer()->deserialize($data, CredentialRecord::class, 'json');
        } catch (\Throwable $e) {
            throw new DomainException('Stored credential is unreadable.', previous: $e);
        }
    }

    private function ceremony(Request $request): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory;

        $origins = (array) config('omnex.webauthn.allowed_origins', []);
        if (count($origins) > 0) {
            $factory->setAllowedOrigins($origins, true);
        }
        $factory->setSecuredRelyingPartyId([
            'localhost',
            '127.0.0.1',
            $request->getHost(),
        ]);
        $factory->setAttestationStatementSupportManager($this->attestationManager());

        return $factory;
    }

    private function attestationManager(): AttestationStatementSupportManager
    {
        $algorithmManager = CoseAlgorithmManager::create()->add(
            ES256::create(),
            RS256::create()
        );

        return new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport,
            PackedAttestationStatementSupport::create($algorithmManager),
            FidoU2FAttestationStatementSupport::create(),
        ]);
    }

    private function serializer(): SerializerInterface
    {
        return (new WebauthnSerializerFactory($this->attestationManager()))->create();
    }

    private function rpId(Request $request): string
    {
        $configured = config('omnex.webauthn.rp_id');

        // config(..., $default) only applies when the key is absent — a null
        // value (unset env) returns null, so fall back explicitly.
        return (string) ($configured !== null && $configured !== '' ? $configured : $request->getHost());
    }

    private function assertCacheKey(string $challenge): string
    {
        return 'passkey-assert:'.Base64UrlSafe::encodeUnpadded($challenge);
    }
}
