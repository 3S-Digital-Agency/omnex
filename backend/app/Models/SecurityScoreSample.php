<?php

namespace App\Models;

use App\Support\Tenancy\HasTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SecurityScoreSample extends Model
{
    use HasTenant, HasUuids;

    protected $fillable = [
        'organization_id',
        'score',
        'open',
        'high',
        'medium',
        'low',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'open' => 'integer',
            'high' => 'integer',
            'medium' => 'integer',
            'low' => 'integer',
        ];
    }
}
