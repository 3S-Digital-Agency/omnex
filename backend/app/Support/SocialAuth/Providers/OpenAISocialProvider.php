<?php

namespace App\Support\SocialAuth\Providers;

use App\Contracts\SocialAuthProviderInterface;
use App\Support\SocialAuth\SocialUser;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI "sign in with ChatGPT account" — OpenID Connect on auth.openai.com.
 * Endpoints come from https://auth.openai.com/.well-known/openid-configuration
 * (authorize, token, userinfo). Scopes: openid profile email.
 */
final class OpenAISocialProvider implements SocialAuthProviderInterface
{
    public function name(): string
    {
        return 'openai';
    }

    public function label(): string
    {
        return 'OpenAI';
    }

    public function isConfigured(): bool
    {
        return filled(config('socialauth.openai.client_id'))
            && filled(config('socialauth.openai.client_secret'))
            && filled(config('socialauth.openai.redirect'));
    }

    public function redirectUrl(string $state): string
    {
        return 'https://auth.openai.com/api/accounts/authorize?'.http_build_query([
            'client_id' => config('socialauth.openai.client_id'),
            'redirect_uri' => config('socialauth.openai.redirect'),
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => $state,
        ]);
    }

    public function userFromCode(string $code): SocialUser
    {
        $token = Http::asForm()->post('https://auth.openai.com/api/accounts/oauth/token', [
            'client_id' => config('socialauth.openai.client_id'),
            'client_secret' => config('socialauth.openai.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('socialauth.openai.redirect'),
        ])->throw()->json();

        $profile = Http::withToken($token['access_token'])
            ->get('https://auth.openai.com/api/accounts/oauth/userinfo')
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
