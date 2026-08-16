<?php

namespace App\Models;

use App\Support\Tenancy\HasTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SecurityFinding extends Model
{
    use HasFactory, HasTenant, HasUuids;

    protected $fillable = [
        'organization_id',
        'rule',
        'dedupe_key',
        'severity',
        'status',
        'resource_type',
        'resource_id',
        'metadata',
        'resolved_at',
        'dismissed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'resolved_at' => 'datetime',
        'dismissed_at' => 'datetime',
    ];
}
