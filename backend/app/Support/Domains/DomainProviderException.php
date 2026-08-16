<?php

namespace App\Support\Domains;

use RuntimeException;

/**
 * Thrown when a registrar's HTTP/XML layer fails (auth, network, upstream
 * error). Distinct from DomainUnavailableException, which is a normal domain
 * state (already taken / not available) and maps to a 422.
 */
class DomainProviderException extends RuntimeException
{
    //
}
