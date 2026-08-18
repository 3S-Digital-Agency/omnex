<?php

namespace App\Support\Ssl;

use RuntimeException;

/**
 * Raised when an SSL provider cannot fulfill an issuance/renewal/revocation
 * request (unconfigured, upstream error, auth failure, …). Controllers map
 * this to a 503; the domain engine treats it as best-effort during auto-issue.
 */
final class SslProviderException extends RuntimeException {}
