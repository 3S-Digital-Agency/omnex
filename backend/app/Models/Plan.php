<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'slug',
        'name',
        'description',
        'price_monthly',
        'price_yearly',
        'currency',
        'features',
        'stripe_price_id',
        'active',
        'sort',
    ];

    protected $casts = [
        'features' => 'array',
        'price_monthly' => 'integer',
        'price_yearly' => 'integer',
        'active' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * @return array<int, string>
     */
    public function featureList(): array
    {
        $features = $this->getAttribute('features');

        return is_array($features) ? array_values(array_filter($features, 'is_string')) : [];
    }
}
