<?php

namespace App\Support\SocialAuth\Providers;

use App\Contracts\SocialAuthProviderInterface;
use App\Support\SocialAuth\SocialAuthException;
use App\Support\SocialAuth\SocialUser;

/**
 * Deterministic identity provider for local/test environments. The "code" IS
 * the email, so tests (and the dev UI) can exercise the full login/link flow
 * without touching a real OAuth provider. No randomness anywhere.
 */
final class SandboxSocialProvider implements SocialAuthProviderInterface
{
    public function name(): string
    {
        return 'sandbox';
    }

    public function label(): string
    {
        return 'Sandbox';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function redirectUrl(string $state): string
    {
        $code = urlencode('demo@omnex.dev');

        return url("/api/v1/auth/sandbox/callback?code={$code}&state={$state}");
    }

    public function userFromCode(string $code): SocialUser
    {
        $email = strtolower(trim($code));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new SocialAuthException('Invalid sandbox identity code.');
        }

        $local = strtok($email, '@');
        $name = $local !== false
            ? implode(' ', array_map('ucfirst', explode('.', $local)))
            : '';

        return new SocialUser(
            id: 'sandbox-'.sha1($email),
            email: $email,
            name: $name === '' ? $email : $name,
            emailVerified: true,
            raw: ['email' => $email],
        );
    }
}
