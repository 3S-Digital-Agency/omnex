<?php

namespace App\Support\Sites;

use RuntimeException;

/**
 * Thrown when the hosting provider's HTTP layer fails (auth, network, upstream
 * error). Distinct from validation failures, which map to a 422.
 */
class SiteProviderException extends RuntimeException
{
    //
}
