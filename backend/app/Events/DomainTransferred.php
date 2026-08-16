<?php

namespace App\Events;

use App\Models\Domain;
use Illuminate\Foundation\Events\Dispatchable;

class DomainTransferred
{
    use Dispatchable;

    public function __construct(public readonly Domain $domain) {}
}
