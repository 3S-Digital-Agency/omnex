<?php

namespace App\Models;

use App\Support\Tenancy\HasTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DnsZone extends Model
{
    use HasFactory, HasUuids, HasTenant;

    protected $fillable = [
        'organization_id',
        'domain_id',
        'provider',
        'status',
        'external_id',
        'dnssec_enabled',
        'dnssec_status',
        'dnssec_ds_records',
    ];

    protected $casts = [
        'dnssec_enabled' => 'boolean',
        'dnssec_ds_records' => 'array',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(DnsRecord::class, 'zone_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(DnsHistory::class, 'zone_id');
    }

    public function propagationChecks(): HasMany
    {
        return $this->hasMany(DnsPropagationCheck::class, 'zone_id');
    }
}
