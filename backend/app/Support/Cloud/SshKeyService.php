<?php

namespace App\Support\Cloud;

use App\Models\SshKey;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Reusable SSH keys for the OMNEX Cloud. A key is a named OpenSSH public key
 * with a computed SHA256 fingerprint (duplicates are rejected per tenant).
 * Servers reference a key by id and snapshot its text at provisioning time,
 * so deleting a key never breaks an existing server.
 */
final class SshKeyService
{
    private const ALLOWED_TYPES = [
        'ssh-ed25519',
        'ssh-rsa',
        'ecdsa-sha2-nistp256',
        'ecdsa-sha2-nistp384',
        'ecdsa-sha2-nistp521',
        'ssh-dss',
    ];

    /**
     * @return array<int, SshKey>
     */
    public function list(): array
    {
        return SshKey::query()
            ->withCount('servers')
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    public function create(string $name, string $publicKey): SshKey
    {
        $name = $this->validateName($name);
        $publicKey = $this->validatePublicKey($publicKey);
        $fingerprint = $this->fingerprint($publicKey);

        if (SshKey::where('fingerprint', $fingerprint)->exists()) {
            throw ValidationException::withMessages(['public_key' => ['This public key is already registered in the organization.']]);
        }

        $key = SshKey::create([
            'name' => $name,
            'public_key' => $publicKey,
            'fingerprint' => $fingerprint,
        ]);

        AuditLogger::record('ssh_key.created', 'ssh_key', $key->id, null, [
            'name' => $key->name,
            'fingerprint' => $key->fingerprint,
        ]);

        return $key;
    }

    public function update(SshKey $key, string $name): SshKey
    {
        $before = ['name' => $key->name, 'fingerprint' => $key->fingerprint];
        $key->update(['name' => $this->validateName($name)]);

        AuditLogger::record('ssh_key.updated', 'ssh_key', $key->id, $before, [
            'name' => $key->name,
            'fingerprint' => $key->fingerprint,
        ]);

        return $key->fresh();
    }

    public function delete(SshKey $key): void
    {
        $before = ['name' => $key->name, 'fingerprint' => $key->fingerprint];

        $inUseBy = (int) $key->servers()->count();

        if ($inUseBy > 0) {
            throw ValidationException::withMessages(['ssh_key' => [
                "This key is used by {$inUseBy} server".($inUseBy > 1 ? 's' : '').'. Remove it from those servers before deleting it.',
            ]]);
        }

        $key->delete();

        AuditLogger::record('ssh_key.deleted', 'ssh_key', null, $before, null);
    }

    /**
     * Generate a brand-new key pair (ed25519 default, rsa 4096 as an option)
     * and register its public half. The private key is returned to the client
     * exactly once. Without a vault password it is NEVER persisted — not in
     * the database, not in the audit log, not in any log. When a vault
     * password is given, the private half is stored encrypted at rest
     * (AES-256-GCM) and can only be recovered with that password
     * (SshKeyService::unlock).
     *
     * @return array{key: SshKey, private_key: string}
     */
    public function generate(string $name, string $type = 'ed25519', ?string $vaultPassword = null): array
    {
        $name = $this->validateName($name);
        $type = $type === 'rsa' ? 'rsa' : 'ed25519';
        $comment = 'omnex-'.Str::slug($name).'@'.(gethostname() ?: 'omnex');

        [$privateKey, $publicKey] = $this->generateKeyPair($type, $comment);

        // The public half is validated (type + base64) and stored with a
        // fingerprint; duplicates per tenant are rejected, exactly like a
        // pasted key. The private half stops existing after this method
        // unless it is sealed into the encrypted vault.
        $key = $this->create($name, $publicKey);

        if ($vaultPassword !== null && $vaultPassword !== '') {
            $this->sealPrivateKey($key, $privateKey, $vaultPassword);
        }

        return ['key' => $key, 'private_key' => $privateKey];
    }

    /**
     * Store a private key in the encrypted vault for later recovery.
     *
     * The key is sealed with AES-256-GCM using a key derived from the vault
     * password (PBKDF2-HMAC-SHA256). The passphrase itself is never stored —
     * only the salt and a verifier derived from the encryption key — so the
     * private key can be recovered only by someone who knows the passphrase.
     */
    public function sealPrivateKey(SshKey $key, string $privateKey, string $vaultPassword): SshKey
    {
        $cipher = (string) config('omnex.cloud.ssh_vault.cipher', 'aes-256-gcm');
        $iterations = (int) config('omnex.cloud.ssh_vault.pbkdf2_iterations', 210000);

        $salt = random_bytes(16);
        $derived = $this->deriveVaultKey($vaultPassword, $salt, $iterations);
        $nonce = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt($privateKey, $cipher, $derived, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($ciphertext === false) {
            throw new \RuntimeException('Vault encryption failed.');
        }

        $key->update([
            'encrypted_private_key' => base64_encode($nonce.$tag.$ciphertext),
            'private_key_salt' => base64_encode($salt),
            'private_key_verifier' => $this->vaultVerifier($derived),
            'vault_enabled_at' => now(),
        ]);

        return $key->fresh();
    }

    /**
     * Recover the private key stored in the vault. The passphrase is checked
     * against the stored verifier before any decryption, so a wrong password
     * is rejected without touching the ciphertext. The recovered key is
     * returned to the caller exactly once and is never logged or persisted.
     */
    public function unlock(SshKey $key, string $vaultPassword): string
    {
        if (! $key->hasPrivateKeyInVault()) {
            throw ValidationException::withMessages(['vault_password' => ['No private key is stored in the vault for this key.']]);
        }

        $cipher = (string) config('omnex.cloud.ssh_vault.cipher', 'aes-256-gcm');
        $iterations = (int) config('omnex.cloud.ssh_vault.pbkdf2_iterations', 210000);

        $salt = base64_decode((string) $key->private_key_salt, true);
        $derived = $this->deriveVaultKey($vaultPassword, $salt === false ? '' : $salt, $iterations);

        if (! hash_equals($this->vaultVerifier($derived), (string) $key->private_key_verifier)) {
            throw ValidationException::withMessages(['vault_password' => ['The vault password is incorrect.']]);
        }

        $blob = base64_decode((string) $key->encrypted_private_key, true);
        $nonce = substr((string) $blob, 0, 12);
        $tag = substr((string) $blob, 12, 16);
        $ciphertext = substr((string) $blob, 28);

        $privateKey = openssl_decrypt($ciphertext, $cipher, $derived, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($privateKey === false) {
            throw ValidationException::withMessages(['vault_password' => ['The vault password is incorrect.']]);
        }

        return $privateKey;
    }

    private function deriveVaultKey(string $password, string $salt, int $iterations): string
    {
        return hash_pbkdf2('sha256', $password, $salt, max(10000, $iterations), 32, true);
    }

    private function vaultVerifier(string $derivedKey): string
    {
        return hash_hmac('sha256', 'omnex.vault.v1', $derivedKey);
    }

    /**
     * @return array{0: string, 1: string} private then public key text
     */
    private function generateKeyPair(string $type, string $comment): array
    {
        $keygen = (new ExecutableFinder)->find('ssh-keygen');

        if ($keygen !== null) {
            return $this->generateWithKeygen($keygen, $type, $comment);
        }

        if ($type === 'rsa') {
            return $this->generateRsaWithOpenSsl($comment);
        }

        throw new \RuntimeException('Neither ssh-keygen nor PHP OpenSSL is available to generate keys.');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function generateWithKeygen(string $keygen, string $type, string $comment): array
    {
        $dir = storage_path('app/sshgen-'.bin2hex(random_bytes(6)));
        @mkdir($dir, 0700, true);
        $file = $dir.'/id_'.($type === 'rsa' ? 'rsa' : 'ed25519');

        $args = [$keygen, '-q', '-t', $type, '-f', $file, '-N', '', '-C', $comment];

        if ($type === 'rsa') {
            $args[] = '-b';
            $args[] = '4096';
        }

        try {
            $process = new Process($args);
            $process->setTimeout(30)->run();

            if (! $process->isSuccessful()) {
                throw new \RuntimeException('ssh-keygen failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
            }

            $privateKey = file_get_contents($file);
            $publicKey = trim((string) file_get_contents($file.'.pub'));

            if ($privateKey === false || $publicKey === '') {
                throw new \RuntimeException('ssh-keygen produced no output.');
            }

            return [$privateKey, $publicKey];
        } finally {
            @unlink($file);
            @unlink($file.'.pub');
            @rmdir($dir);
        }
    }

    /**
     * Fallback for environments without ssh-keygen: an RSA pair from OpenSSL.
     * The private key is exported as PKCS#8 (accepted by OpenSSH); the public
     * half is encoded in the OpenSSH `ssh-rsa` format.
     *
     * @return array{0: string, 1: string}
     */
    private function generateRsaWithOpenSsl(string $comment): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 4096,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            throw new \RuntimeException('OpenSSL could not generate the RSA key pair.');
        }

        $privateKey = '';
        openssl_pkey_export($resource, $privateKey);

        $details = openssl_pkey_get_details($resource);
        $rsa = $details['rsa'] ?? null;

        if ($rsa === null) {
            throw new \RuntimeException('OpenSSL could not read the generated key.');
        }

        $blob = pack('N', strlen('ssh-rsa')).'ssh-rsa';
        $blob .= $this->encodeMpint($rsa['e']);
        $blob .= $this->encodeMpint($rsa['n']);

        return [$privateKey, 'ssh-rsa '.base64_encode($blob).' '.$comment];
    }

    private function encodeMpint(string $value): string
    {
        $value = ltrim($value, "\0");

        if ($value === '') {
            return "\0\0\0\0";
        }

        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\0".$value;
        }

        return pack('N', strlen($value)).$value;
    }

    /**
     * OpenSSH SHA256 fingerprint, e.g. "SHA256:9wHFrPZsh7lX…".
     */
    public function fingerprint(string $publicKey): string
    {
        $parts = preg_split('/\s+/', trim($publicKey));

        $decoded = base64_decode($parts[1] ?? '', true);

        return 'SHA256:'.base64_encode(hash('sha256', $decoded === false ? '' : $decoded, true));
    }

    private function validateName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['The name is required.']]);
        }

        if (mb_strlen($name) > 64) {
            throw ValidationException::withMessages(['name' => ['The name must not exceed 64 characters.']]);
        }

        return $name;
    }

    public function validatePublicKey(string $publicKey): string
    {
        $publicKey = trim($publicKey);

        $parts = preg_split('/\s+/', $publicKey);

        if (count($parts) < 2) {
            throw ValidationException::withMessages(['public_key' => ['A valid OpenSSH public key is required (type + base64 body).']]);
        }

        if (! in_array($parts[0], self::ALLOWED_TYPES, true)) {
            throw ValidationException::withMessages(['public_key' => ['Unsupported key type ['.$parts[0].'].']]);
        }

        if (base64_decode($parts[1], true) === false) {
            throw ValidationException::withMessages(['public_key' => ['The key body is not valid base64.']]);
        }

        return implode(' ', array_slice($parts, 0, 2));
    }
}
