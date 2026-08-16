<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SshKeyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'fingerprint' => $this->fingerprint,
            // The full body is needed to paste elsewhere, but the fingerprint
            // is the identity; expose the key only for the owning org.
            'public_key' => $this->public_key,
            // Whether a private key is sealed in the encrypted vault
            // (recoverable with the vault password). The ciphertext itself is
            // never exposed — only its presence.
            'has_private_key' => $this->hasPrivateKeyInVault(),
            'vault_enabled_at' => $this->vault_enabled_at?->toIso8601String(),
            // Number of servers referencing this saved key. A key in use
            // cannot be deleted until it is removed from those servers.
            'servers_count' => (int) ($this->servers_count ?? $this->servers()->count()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
