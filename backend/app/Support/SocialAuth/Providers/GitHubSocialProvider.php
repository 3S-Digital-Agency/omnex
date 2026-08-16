<?php

namespace App\Support\SocialAuth\Providers;

use App\Contracts\SocialAuthProviderInterface;
use App\Support\SocialAuth\SocialUser;
use Illuminate\Support\Facades\Http;

/**
 * GitHub OAuth (authorization-code flow). The profile endpoint omits the
 * email when the user hides it, so a second call to /user/emails selects the
 * primary verified address and only then is `emailVerified` true.
 */
final class GitHubSocialProvider implements SocialAuthProviderInterface
{
    public function name(): string
    {
        return 'github';
    }

    public function label(): string
    {
        return 'GitHub';
    }

    public function isConfigured(): bool
    {
        return filled(config('socialauth.github.client_id'))
            && filled(config('socialauth.github.client_secret'))
            && filled(config('socialauth.github.redirect'));
    }

    public function redirectUrl(string $state): string
    {
        return 'https://github.com/login/oauth/authorize?'.http_build_query([
            'client_id' => config('socialauth.github.client_id'),
            'redirect_uri' => config('socialauth.github.redirect'),
            'response_type' => 'code',
            'scope' => 'read:user user:email',
            'state' => $state,
        ]);
    }

    public function userFromCode(string $code): SocialUser
    {
        $token = Http::withHeaders(['Accept' => 'application/json'])
            ->asForm()
            ->post('https://github.com/login/oauth/access_token', [
                'client_id' => config('socialauth.github.client_id'),
                'client_secret' => config('socialauth.github.client_secret'),
                'code' => $code,
                'redirect_uri' => config('socialauth.github.redirect'),
            ])->throw()->json();

        $profile = Http::withToken($token['access_token'])
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get('https://api.github.com/user')
            ->throw()
            ->json();

        $email = (string) ($profile['email'] ?? '');
        $emailVerified = false;

        // The primary address may be private; resolve it explicitly and only
        // trust it when GitHub marks it verified.
        if ($email === '') {
            $emails = Http::withToken($token['access_token'])
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get('https://api.github.com/user/emails')
                ->throw()
                ->json();

            foreach ($emails as $entry) {
                if (($entry['primary'] ?? false) === true) {
                    $email = (string) ($entry['email'] ?? '');
                    $emailVerified = (bool) ($entry['verified'] ?? false);
                    break;
                }
            }
        } else {
            $emailVerified = true;
        }

        return new SocialUser(
            id: (string) $profile['id'],
            email: $email,
            name: (string) ($profile['name'] ?? $profile['login'] ?? ''),
            avatarUrl: $profile['avatar_url'] ?? null,
            emailVerified: $emailVerified,
            raw: $profile,
        );
    }
}
