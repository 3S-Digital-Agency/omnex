<?php

namespace App\Support\SocialAuth\Providers;

use App\Contracts\SocialAuthProviderInterface;
use App\Support\SocialAuth\SocialUser;
use Illuminate\Support\Facades\Http;

final class MicrosoftSocialProvider implements SocialAuthProviderInterface
{
    public function name(): string
    {
        return 'microsoft';
    }

    public function label(): string
    {
        return 'Microsoft';
    }

    public function isConfigured(): bool
    {
        return filled(config('socialauth.microsoft.client_id'))
            && filled(config('socialauth.microsoft.client_secret'))
            && filled(config('socialauth.microsoft.redirect'));
    }

    private function endpoint(string $path): string
    {
        $tenant = config('socialauth.microsoft.tenant', 'common');

        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/{$path}";
    }

    public function redirectUrl(string $state): string
    {
        return $this->endpoint('authorize').'?'.http_build_query([
            'client_id' => config('socialauth.microsoft.client_id'),
            'redirect_uri' => config('socialauth.microsoft.redirect'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]);
    }

    public function userFromCode(string $code): SocialUser
    {
        $token = Http::asForm()->post($this->endpoint('token'), [
            'client_id' => config('socialauth.microsoft.client_id'),
            'client_secret' => config('socialauth.microsoft.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('socialauth.microsoft.redirect'),
        ])->throw()->json();

        $profile = Http::withToken($token['access_token'])
            ->get('https://graph.microsoft.com/oidc/userinfo')
            ->throw()
            ->json();

        return new SocialUser(
            id: (string) $profile['sub'],
            email: (string) ($profile['email'] ?? ''),
            name: (string) ($profile['name'] ?? ($profile['email'] ?? '')),
            avatarUrl: $profile['picture'] ?? null,
            emailVerified: (bool) ($profile['email_verified'] ?? true),
            raw: $profile,
        );
    }
}
