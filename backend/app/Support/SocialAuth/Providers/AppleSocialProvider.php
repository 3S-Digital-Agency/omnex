<?php

namespace App\Support\SocialAuth\Providers;

use App\Contracts\SocialAuthProviderInterface;
use App\Support\SocialAuth\SocialAuthException;
use App\Support\SocialAuth\SocialUser;
use Illuminate\Support\Facades\Http;

/**
 * Sign in with Apple. Apple's token endpoint requires a client_secret that is
 * itself an ES256 JWT generated from the developer .p8 key — provide the
 * pre-generated value as APPLE_CLIENT_SECRET (it is valid for months and can
 * be cached). The id_token payload is decoded for sub/email; signature
 * verification against Apple's JWKS is a production hardening TODO before a
 * real Apple key is enabled.
 */
final class AppleSocialProvider implements SocialAuthProviderInterface
{
    public function name(): string
    {
        return 'apple';
    }

    public function label(): string
    {
        return 'Apple';
    }

    public function isConfigured(): bool
    {
        return filled(config('socialauth.apple.client_id'))
            && filled(config('socialauth.apple.client_secret'))
            && filled(config('socialauth.apple.redirect'));
    }

    public function redirectUrl(string $state): string
    {
        return 'https://appleid.apple.com/auth/authorize?'.http_build_query([
            'client_id' => config('socialauth.apple.client_id'),
            'redirect_uri' => config('socialauth.apple.redirect'),
            'response_type' => 'code',
            'scope' => 'name email',
            'response_mode' => 'query',
            'state' => $state,
        ]);
    }

    public function userFromCode(string $code): SocialUser
    {
        $token = Http::asForm()->post('https://appleid.apple.com/auth/token', [
            'client_id' => config('socialauth.apple.client_id'),
            'client_secret' => config('socialauth.apple.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('socialauth.apple.redirect'),
        ])->throw()->json();

        if (! isset($token['id_token'])) {
            throw new SocialAuthException('Apple did not return an id_token.');
        }

        $payload = $this->decodePayload($token['id_token']);

        return new SocialUser(
            id: (string) ($payload['sub'] ?? ''),
            email: (string) ($payload['email'] ?? ''),
            name: (string) ($payload['name'] ?? ($payload['email'] ?? '')),
            emailVerified: (bool) ($payload['email_verified'] ?? true),
            raw: $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $jwt): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new SocialAuthException('Malformed Apple id_token.');
        }

        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);

        if ($json === false) {
            throw new SocialAuthException('Malformed Apple id_token payload.');
        }

        return json_decode($json, true) ?? [];
    }
}
