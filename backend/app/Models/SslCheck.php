<?php

namespace App\Models;

use App\Support\Tenancy\HasTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SslCheck extends Model
{
    use HasTenant, HasUuids;

    public const STATUS_VALID = 'valid';

    public const STATUS_EXPIRING = 'expiring';

    public const STATUS_INVALID = 'invalid';

    protected $fillable = [
        'organization_id',
        'target_type',
        'target_id',
        'status',
        'days_remaining',
        'details',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'checked_at' => 'datetime',
        ];
    }
}
