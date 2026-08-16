<?php

namespace App\Models;

use App\Support\Tenancy\HasTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Domain extends Model
{
    use HasFactory, HasUuids, HasTenant;

    protected $fillable = [
        'organization_id',
        'name',
        'status',
        'provider',
        'external_id',
        'registered_at',
        'expires_at',
        'expiration_notified_at',
        'auto_renew',
        'privacy_protection',
        'transfer_lock',
        'nameservers',
        'contacts',
        'auth_code',
    ];

    protected $casts = [
        'registered_at' => 'datetime',
        'expires_at' => 'datetime',
        'expiration_notified_at' => 'datetime',
        'auto_renew' => 'boolean',
        'privacy_protection' => 'boolean',
        'transfer_lock' => 'boolean',
        'nameservers' => 'array',
        'contacts' => 'array',
    ];

    public function zone(): HasOne
    {
        return $this->hasOne(DnsZone::class);
    }
}
