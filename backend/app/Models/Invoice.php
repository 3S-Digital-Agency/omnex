<?php

namespace App\Models;

use App\Support\Tenancy\HasTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use HasFactory, HasTenant, HasUuids;

    protected $fillable = [
        'organization_id',
        'subscription_id',
        'provider',
        'provider_invoice_id',
        'number',
        'amount',
        'discount',
        'credit_applied',
        'amount_due',
        'currency',
        'status',
        'paid_at',
        'period_start',
        'period_end',
    ];

    protected $casts = [
        'amount' => 'integer',
        'discount' => 'integer',
        'credit_applied' => 'integer',
        'amount_due' => 'integer',
        'paid_at' => 'datetime',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
