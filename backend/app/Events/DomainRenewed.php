<?php

namespace App\Events;

use App\Models\Domain;
use Illuminate\Foundation\Events\Dispatchable;

class DomainRenewed
{
    use Dispatchable;

    public function __construct(public readonly Domain $domain, public readonly int $years) {}
}
