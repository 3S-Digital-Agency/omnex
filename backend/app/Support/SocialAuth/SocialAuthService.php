<?php

namespace App\Support\SocialAuth;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * OMNEX is the system of record for users and sessions; providers only supply
 * a verified external identity. resolve() implements the three outcomes:
 *  1. link the identity to an already-authenticated user,
 *  2. sign in an existing user (existing social account, or verified-email match),
 *  3. register a brand-new user.
 */
final class SocialAuthService
{
    public function __construct(private SocialAuthRegistry $registry) {}

    public function provider(string $name): \App\Contracts\SocialAuthProviderInterface
    {
        $provider = $this->registry->get($name);

        if (! $provider->isConfigured()) {
            throw new SocialAuthException("The [{$name}] provider is not configured.");
        }

        return $provider;
    }

    public function resolve(string $providerName, string $code, ?User $actor): User
    {
        $socialUser = $this->provider($providerName)->userFromCode($code);

        if ($socialUser->id === '' || $socialUser->email === '') {
            throw new SocialAuthException('The provider did not return a usable identity.');
        }

        if ($actor !== null) {
            return $this->link($actor, $providerName, $socialUser);
        }

        $account = SocialAccount::query()
            ->where('provider', $providerName)
            ->where('provider_user_id', $socialUser->id)
            ->first();

        if ($account !== null) {
            $this->refresh($account, $socialUser);

            return $account->user;
        }

        // Interoperability: a verified email bridges providers to the same
        // account, so logging in with Google after signing up with Microsoft
        // lands on the same OMNEX user instead of creating a duplicate.
        if ($socialUser->emailVerified) {
            $user = User::query()->where('email', strtolower($socialUser->email))->first();

            if ($user !== null) {
                $this->attach($user, $providerName, $socialUser);

                return $user;
            }
        }

        return $this->register($providerName, $socialUser);
    }

    public function unlink(User $user, string $providerName): void
    {
        SocialAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', $providerName)
            ->delete();
    }

    private function link(User $actor, string $providerName, SocialUser $socialUser): User
    {
        $existing = SocialAccount::query()
            ->where('provider', $providerName)
            ->where('provider_user_id', $socialUser->id)
            ->first();

        if ($existing !== null && $existing->user_id !== $actor->id) {
            throw new SocialAuthException('This account is already linked to another OMNEX user.');
        }

        if ($existing === null) {
            $this->attach($actor, $providerName, $socialUser);
        } else {
            $this->refresh($existing, $socialUser);
        }

        return $actor;
    }

    private function register(string $providerName, SocialUser $socialUser): User
    {
        $user = User::create([
            'name' => $socialUser->name !== '' ? $socialUser->name : $socialUser->email,
            'email' => strtolower($socialUser->email),
            'password' => Str::password(32),
            'email_verified_at' => $socialUser->emailVerified ? now() : null,
            'status' => 'active',
        ]);

        $this->attach($user, $providerName, $socialUser);

        return $user;
    }

    private function attach(User $user, string $providerName, SocialUser $socialUser): SocialAccount
    {
        return SocialAccount::updateOrCreate(
            ['provider' => $providerName, 'provider_user_id' => $socialUser->id],
            [
                'user_id' => $user->id,
                'provider_email' => strtolower($socialUser->email),
                'name' => $socialUser->name !== '' ? $socialUser->name : null,
                'avatar_url' => $socialUser->avatarUrl,
                'raw' => $socialUser->raw,
            ],
        );
    }

    private function refresh(SocialAccount $account, SocialUser $socialUser): void
    {
        $account->update([
            'provider_email' => strtolower($socialUser->email),
            'name' => $socialUser->name !== '' ? $socialUser->name : null,
            'avatar_url' => $socialUser->avatarUrl,
            'raw' => $socialUser->raw,
        ]);
    }
}
