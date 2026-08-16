<?php

namespace App\Support\SocialAuth;

final readonly class SocialUser
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public string $email,
        public string $name,
        public ?string $avatarUrl = null,
        public bool $emailVerified = false,
        public array $raw = [],
    ) {}
}
