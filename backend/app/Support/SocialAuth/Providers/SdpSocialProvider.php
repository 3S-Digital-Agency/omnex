<?php

namespace App\Support\SocialAuth\Providers;

use App\Contracts\SocialAuthProviderInterface;
use App\Support\SocialAuth\SocialUser;
use Illuminate\Support\Facades\Http;

/**
 * Serveurs du Peuple — Path A (Nextcloud core OAuth2 app).
 *
 * Authorization-code flow with identity read from the OCS `cloud/user`
 * endpoint. Deliberate choices, agreed with SdP:
 *
 *  - `emailVerified` is always false: the core OAuth2 app has no scopes and a
 *    Nextcloud address is not necessarily verified. OMNEX must not claim
 *    otherwise (verified email comes with Path B's `email_verified` claim).
 *  - The stable id is the Nextcloud username (`user_id` from the token
 *    response), never the email — people change addresses, usernames stay.
 *
 * Path A is for testing and interop. Because its access token carries full
 * account rights (no scope granularity), production "recommended" status
 * depends on SdP's Path B OIDC provider (`openid email profile`); the switch
 * changes URLs, not this class's shape.
 */
final class SdpSocialProvider implements SocialAuthProviderInterface
{
    public function name(): string
    {
        return 'sdp';
    }

    public function label(): string
    {
        return 'Serveurs du Peuple';
    }

    public function isConfigured(): bool
    {
        return filled(config('socialauth.sdp.client_id'))
            && filled(config('socialauth.sdp.client_secret'))
            && filled(config('socialauth.sdp.redirect'));
    }

    private function base(): string
    {
        return rtrim((string) config('socialauth.sdp.base_url'), '/');
    }

    public function redirectUrl(string $state): string
    {
        return $this->base().'/index.php/apps/oauth2/authorize?'.http_build_query([
            'client_id' => config('socialauth.sdp.client_id'),
            'redirect_uri' => config('socialauth.sdp.redirect'),
            'response_type' => 'code',
            'state' => $state,
        ]);
    }

    public function userFromCode(string $code): SocialUser
    {
        $token = Http::asForm()->post($this->base().'/index.php/apps/oauth2/api/v1/token', [
            'client_id' => config('socialauth.sdp.client_id'),
            'client_secret' => config('socialauth.sdp.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('socialauth.sdp.redirect'),
        ])->throw()->json();

        $profile = Http::withToken($token['access_token'])
            ->withHeaders(['OCS-APIRequest' => 'true', 'Accept' => 'application/json'])
            ->get($this->base().'/ocs/v2.php/cloud/user')
            ->throw()
            ->json('ocs.data', []);

        // The token response already carries the stable username; fall back to
        // the OCS payload's id for older instances.
        $id = (string) ($token['user_id'] ?? $profile['id'] ?? '');

        // Nextcloud has returned the display name as both `displayname` and
        // `display-name` across versions — accept either.
        $name = (string) ($profile['displayname'] ?? $profile['display-name'] ?? $id);

        return new SocialUser(
            id: $id,
            email: (string) ($profile['email'] ?? ''),
            name: $name,
            avatarUrl: $id !== '' ? $this->base()."/index.php/avatar/{$id}/128" : null,
            emailVerified: false,
            raw: $profile,
        );
    }
}
