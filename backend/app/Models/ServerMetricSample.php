<?php

namespace App\Models;

use App\Support\Tenancy\HasTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerMetricSample extends Model
{
    use HasFactory, HasTenant, HasUuids;

    protected $fillable = [
        'organization_id',
        'server_id',
        'cpu',
        'memory_used',
        'memory_total',
        'disk_used',
        'disk_total',
        'sampled_at',
    ];

    protected $casts = [
        'sampled_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }
}
