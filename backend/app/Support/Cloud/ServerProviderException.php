<?php

namespace App\Support\Cloud;

use RuntimeException;

/**
 * Thrown when the compute provider's HTTP layer fails (auth, network, upstream
 * error). Distinct from validation failures, which map to a 422.
 */
class ServerProviderException extends RuntimeException
{
    //
}
