<?php

namespace App\Support\SocialAuth\Providers;

use App\Contracts\SocialAuthProviderInterface;
use App\Support\SocialAuth\SocialUser;
use Illuminate\Support\Facades\Http;

final class AmazonSocialProvider implements SocialAuthProviderInterface
{
    public function name(): string
    {
        return 'amazon';
    }

    public function label(): string
    {
        return 'Amazon';
    }

    public function isConfigured(): bool
    {
        return filled(config('socialauth.amazon.client_id'))
            && filled(config('socialauth.amazon.client_secret'))
            && filled(config('socialauth.amazon.redirect'));
    }

    public function redirectUrl(string $state): string
    {
        return 'https://www.amazon.com/ap/oa?'.http_build_query([
            'client_id' => config('socialauth.amazon.client_id'),
            'redirect_uri' => config('socialauth.amazon.redirect'),
            'response_type' => 'code',
            'scope' => 'profile',
            'state' => $state,
        ]);
    }

    public function userFromCode(string $code): SocialUser
    {
        $token = Http::asForm()->post('https://api.amazon.com/auth/o2/token', [
            'client_id' => config('socialauth.amazon.client_id'),
            'client_secret' => config('socialauth.amazon.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('socialauth.amazon.redirect'),
        ])->throw()->json();

        $profile = Http::withToken($token['access_token'])
            ->get('https://api.amazon.com/user/profile')
            ->throw()
            ->json();

        return new SocialUser(
            id: (string) $profile['user_id'],
            email: (string) ($profile['email'] ?? ''),
            name: (string) ($profile['name'] ?? ($profile['email'] ?? '')),
            emailVerified: isset($profile['email']),
            raw: $profile,
        );
    }
}
