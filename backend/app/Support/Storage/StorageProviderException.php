<?php

namespace App\Support\Storage;

use RuntimeException;

/**
 * Thrown when the object store's HTTP layer fails (auth, network, upstream
 * error). Distinct from validation failures, which map to a 422.
 */
class StorageProviderException extends RuntimeException
{
    //
}
