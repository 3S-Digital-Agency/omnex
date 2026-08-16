<?php

namespace App\Events;

use App\Models\DnsRecord;
use App\Models\DnsZone;
use Illuminate\Foundation\Events\Dispatchable;

class DnsRecordChanged
{
    use Dispatchable;

    public function __construct(
        public readonly DnsZone $zone,
        public readonly string $action,
        public readonly ?DnsRecord $record,
        public readonly ?array $before = null,
        public readonly ?array $after = null,
    ) {}
}
