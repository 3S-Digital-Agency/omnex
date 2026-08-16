<?php

namespace App\Support\Billing;

use RuntimeException;

/**
 * Thrown when a payment provider's HTTP or webhook layer fails (invalid
 * signature, auth, network, upstream error). Distinct from validation
 * failures, which map to a 422.
 */
class PaymentProviderException extends RuntimeException
{
    //
}
