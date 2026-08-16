<?php

namespace App\Contracts;

use App\Support\SocialAuth\SocialUser;

/**
 * Port for OAuth / OpenID Connect identity providers. OMNEX owns the user
 * record and the session; a provider only supplies a verified external
 * identity. Implementations must never mutate OMNEX state — the
 * SocialAuthService is the system of record, exactly like the Domain/DNS
 * provider ports.
 */
interface SocialAuthProviderInterface
{
    /** Stable machine key, e.g. "google". */
    public function name(): string;

    /** Human label for buttons, e.g. "Google". */
    public function label(): string;

    /** Whether this provider has the credentials required to run. */
    public function isConfigured(): bool;

    /** Absolute authorization URL the browser should be sent to. */
    public function redirectUrl(string $state): string;

    /** Exchange an authorization code for the authenticated external user. */
    public function userFromCode(string $code): SocialUser;
}
