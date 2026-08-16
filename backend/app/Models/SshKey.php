<?php

namespace App\Models;

use App\Support\Tenancy\HasTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SshKey extends Model
{
    use HasFactory, HasTenant, HasUuids;

    protected $fillable = [
        'organization_id',
        'name',
        'public_key',
        'fingerprint',
        // Encrypted private-key vault (SshKeyService). Only ciphertext + KDF
        // salt + verifier are stored — never the plaintext private key.
        'encrypted_private_key',
        'private_key_salt',
        'private_key_verifier',
        'vault_enabled_at',
    ];

    protected $casts = [
        'vault_enabled_at' => 'datetime',
    ];

    public function hasPrivateKeyInVault(): bool
    {
        return $this->encrypted_private_key !== null && $this->vault_enabled_at !== null;
    }

    /**
     * Servers currently referencing this saved key (tenant-scoped).
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class, 'ssh_key_id');
    }
}
