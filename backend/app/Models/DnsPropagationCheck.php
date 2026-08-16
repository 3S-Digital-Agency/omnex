<?php

namespace App\Models;

use App\Support\Tenancy\HasTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsPropagationCheck extends Model
{
    use HasFactory, HasUuids, HasTenant;

    protected $fillable = [
        'organization_id',
        'zone_id',
        'nameserver',
        'record_type',
        'record_name',
        'expected',
        'observed',
        'status',
        'checked_at',
    ];

    protected $casts = [
        'expected' => 'array',
        'observed' => 'array',
        'checked_at' => 'datetime',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'zone_id');
    }
}
