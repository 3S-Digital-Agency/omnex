<?php

namespace App\Http\Controllers;

use App\Http\Resources\SshKeyResource;
use App\Models\SshKey;
use App\Support\Audit\AuditLogger;
use App\Support\Cloud\SshKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SshKeyController extends Controller
{
    public function __construct(private SshKeyService $keys) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('cloud.read');

        return response()->json(['data' => SshKeyResource::collection($this->keys->list())]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('cloud.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'public_key' => ['required', 'string'],
        ]);

        return response()->json(new SshKeyResource($this->keys->create($data['name'], $data['public_key'])), 201);
    }

    /**
     * Generate a fresh key pair (ed25519 or rsa 4096). The private key is
     * returned in this response exactly once. With an optional `vault_password`
     * the private half is sealed into the encrypted vault (recoverable later
     * via unlock); without it, the private key is never stored server-side.
     */
    public function generate(Request $request): JsonResponse
    {
        $this->authorize('cloud.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'type' => ['sometimes', 'string', Rule::in(['ed25519', 'rsa'])],
            'vault_password' => ['sometimes', 'string', 'min:8'],
        ]);

        $result = $this->keys->generate($data['name'], $data['type'] ?? 'ed25519', $data['vault_password'] ?? null);

        return response()->json([
            'data' => new SshKeyResource($result['key']),
            'private_key' => $result['private_key'],
        ], 201);
    }

    /**
     * Recover the private key sealed in the vault. The vault password is
     * verified (never stored) and the plaintext is returned exactly once —
     * it is never logged or persisted. An audit entry records the unlock
     * event without the key material.
     */
    public function unlock(Request $request, string $sshKey): JsonResponse
    {
        $this->authorize('cloud.manage');

        $data = $request->validate([
            'vault_password' => ['required', 'string'],
        ]);

        $key = SshKey::findOrFail($sshKey);
        $privateKey = $this->keys->unlock($key, $data['vault_password']);

        AuditLogger::record('ssh_key.unlocked', 'ssh_key', $key->id, null, [
            'name' => $key->name,
            'fingerprint' => $key->fingerprint,
        ]);

        return response()->json([
            'data' => new SshKeyResource($key),
            'private_key' => $privateKey,
        ]);
    }

    public function update(Request $request, string $sshKey): JsonResponse
    {
        $this->authorize('cloud.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
        ]);

        return response()->json(new SshKeyResource($this->keys->update(SshKey::findOrFail($sshKey), $data['name'])));
    }

    public function destroy(Request $request, string $sshKey): JsonResponse
    {
        $this->authorize('cloud.manage');

        $this->keys->delete(SshKey::findOrFail($sshKey));

        return response()->json(null, 204);
    }
}
