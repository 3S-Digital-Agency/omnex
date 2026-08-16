<?php

namespace App\Support\SocialAuth\Providers;

use App\Contracts\SocialAuthProviderInterface;
use App\Support\SocialAuth\SocialUser;
use Illuminate\Support\Facades\Http;

final class FacebookSocialProvider implements SocialAuthProviderInterface
{
    public function name(): string
    {
        return 'facebook';
    }

    public function label(): string
    {
        return 'Facebook';
    }

    public function isConfigured(): bool
    {
        return filled(config('socialauth.facebook.client_id'))
            && filled(config('socialauth.facebook.client_secret'))
            && filled(config('socialauth.facebook.redirect'));
    }

    public function redirectUrl(string $state): string
    {
        return 'https://www.facebook.com/v20.0/dialog/oauth?'.http_build_query([
            'client_id' => config('socialauth.facebook.client_id'),
            'redirect_uri' => config('socialauth.facebook.redirect'),
            'response_type' => 'code',
            'scope' => 'email',
            'state' => $state,
        ]);
    }

    public function userFromCode(string $code): SocialUser
    {
        $token = Http::get('https://graph.facebook.com/v20.0/oauth/access_token', [
            'client_id' => config('socialauth.facebook.client_id'),
            'client_secret' => config('socialauth.facebook.client_secret'),
            'redirect_uri' => config('socialauth.facebook.redirect'),
            'code' => $code,
        ])->throw()->json();

        $profile = Http::get('https://graph.facebook.com/me', [
            'access_token' => $token['access_token'],
            'fields' => 'id,name,email',
        ])->throw()->json();

        return new SocialUser(
            id: (string) $profile['id'],
            email: (string) ($profile['email'] ?? ''),
            name: (string) ($profile['name'] ?? ($profile['email'] ?? '')),
            emailVerified: isset($profile['email']),
            raw: $profile,
        );
    }
}
