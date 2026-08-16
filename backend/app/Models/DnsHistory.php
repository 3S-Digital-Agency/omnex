<?php

namespace App\Models;

use App\Support\Tenancy\HasTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsHistory extends Model
{
    use HasFactory, HasUuids, HasTenant;

    // The migration uses the singular "dns_history" table name.
    protected $table = 'dns_history';

    // History entries are immutable: no update path.
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'zone_id',
        'record_id',
        'user_id',
        'action',
        'before',
        'after',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'zone_id');
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(DnsRecord::class, 'record_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
