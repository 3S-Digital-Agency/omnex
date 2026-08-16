<?php

namespace App\Support\SocialAuth\Providers;

use App\Contracts\SocialAuthProviderInterface;
use App\Support\SocialAuth\SocialUser;
use Illuminate\Support\Facades\Http;

final class GoogleSocialProvider implements SocialAuthProviderInterface
{
    public function name(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return 'Google';
    }

    public function isConfigured(): bool
    {
        return filled(config('socialauth.google.client_id'))
            && filled(config('socialauth.google.client_secret'))
            && filled(config('socialauth.google.redirect'));
    }

    public function redirectUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('socialauth.google.client_id'),
            'redirect_uri' => config('socialauth.google.redirect'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]);
    }

    public function userFromCode(string $code): SocialUser
    {
        $token = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('socialauth.google.client_id'),
            'client_secret' => config('socialauth.google.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('socialauth.google.redirect'),
        ])->throw()->json();

        $profile = Http::withToken($token['access_token'])
            ->get('https://openidconnect.googleapis.com/v1/userinfo')
            ->throw()
            ->json();

        return new SocialUser(
            id: (string) $profile['sub'],
            email: (string) ($profile['email'] ?? ''),
            name: (string) ($profile['name'] ?? ($profile['email'] ?? '')),
            avatarUrl: $profile['picture'] ?? null,
            emailVerified: (bool) ($profile['email_verified'] ?? false),
            raw: $profile,
        );
    }
}
