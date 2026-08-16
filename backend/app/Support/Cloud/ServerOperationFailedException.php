<?php

namespace App\Support\Cloud;

use RuntimeException;

/**
 * Thrown by a provider when an operation on a server fails (provisioning
 * rejected, reboot refused, rebuild failed…). Carries the upstream reason so
 * ServerService can persist it on the operation record.
 */
final class ServerOperationFailedException extends RuntimeException
{
    //
}
