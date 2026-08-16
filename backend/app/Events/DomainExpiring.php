<?php

namespace App\Events;

use App\Models\Domain;
use Illuminate\Foundation\Events\Dispatchable;

class DomainExpiring
{
    use Dispatchable;

    public function __construct(public readonly Domain $domain, public readonly int $days) {}
}
