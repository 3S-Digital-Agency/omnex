<?php

namespace App\Models;

use App\Support\Tenancy\HasTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    use HasFactory, HasTenant, HasUuids;

    protected $fillable = [
        'organization_id',
        'name',
        'region',
        'plan',
        'image',
        'provider',
        'provider_server_id',
        'status',
        'ipv4',
        'ipv6',
        'ssh_key',
        'ssh_key_id',
        'tags',
        'alert_suppressed_at',
        'snapshot_frequency',
        'snapshot_retention_days',
        'last_snapshot_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'alert_suppressed_at' => 'array',
        'last_snapshot_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(ServerOperation::class, 'server_id')->orderByDesc('created_at');
    }

    public function metricSamples(): HasMany
    {
        return $this->hasMany(ServerMetricSample::class, 'server_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ServerSnapshot::class, 'server_id')->orderByDesc('created_at');
    }

    public function snapshotEnabled(): bool
    {
        return $this->snapshot_frequency !== null && $this->snapshot_frequency !== 'disabled';
    }
}
