<?php

namespace App\Events;

use App\Models\Domain;
use Illuminate\Foundation\Events\Dispatchable;

class DomainUpdated
{
    use Dispatchable;

    public function __construct(
        public readonly Domain $domain,
        public readonly array $before,
        public readonly array $after,
    ) {}
}
