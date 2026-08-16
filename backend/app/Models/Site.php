<?php

namespace App\Models;

use App\Support\Tenancy\HasTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use HasFactory, HasTenant, HasUuids;

    protected $fillable = [
        'organization_id',
        'name',
        'framework',
        'git_url',
        'git_branch',
        'provider',
        'provider_site_id',
        'status',
        'current_deployment_id',
        'url',
        'environment_variables',
    ];

    protected $casts = [
        // Encrypts the env-var map at rest. Values are never returned by the
        // API resource — only the key names are exposed.
        'environment_variables' => 'encrypted:array',
    ];

    public function currentDeployment(): BelongsTo
    {
        return $this->belongsTo(SiteDeployment::class, 'current_deployment_id');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(SiteDeployment::class, 'site_id')->orderByDesc('number');
    }

    /**
     * The decrypted environment map (server-side only — never serialized).
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        $value = $this->getAttribute('environment_variables');

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<int, string>
     */
    public function environmentKeys(): array
    {
        return array_keys($this->environment());
    }
}
