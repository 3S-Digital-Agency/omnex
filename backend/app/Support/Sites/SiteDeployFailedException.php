<?php

namespace App\Support\Sites;

use RuntimeException;

/**
 * Thrown by a provider when a build/deploy fails. Carries the build logs so
 * SiteService can persist them and roll the site back to the previous live
 * deployment automatically.
 */
final class SiteDeployFailedException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $logs = '',
    ) {
        parent::__construct($message);
    }

    public function getLogs(): string
    {
        return $this->logs;
    }
}
