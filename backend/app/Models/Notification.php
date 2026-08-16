<?php

namespace App\Models;

use App\Support\Tenancy\HasTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasFactory, HasTenant, HasUuids;

    protected $fillable = [
        'organization_id',
        'user_id',
        'type',
        'severity',
        'title',
        'body',
        'data',
        'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Optional client-side route the notification links to (e.g. the domain
     * detail page). Stored in the `data` payload so the bell can navigate
     * without a dedicated column.
     */
    public function getRouteAttribute(): ?string
    {
        $route = $this->data['route'] ?? null;

        return is_string($route) ? $route : null;
    }
}
