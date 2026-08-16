<?php

namespace App\Models;

use App\Support\Tenancy\HasTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsRecord extends Model
{
    use HasFactory, HasTenant, HasUuids;

    protected $fillable = [
        'organization_id',
        'zone_id',
        'type',
        'name',
        'content',
        'ttl',
        'priority',
        'proxied',
        'external_id',
    ];

    protected $casts = [
        'ttl' => 'integer',
        'priority' => 'integer',
        'proxied' => 'boolean',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'zone_id');
    }
}
