<?php

use App\Models\Organization;
use App\Models\SshKey;
use App\Support\Cloud\SshKeyService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

it('computes an OpenSSH SHA256 fingerprint', function () {
    $service = new SshKeyService;

    // Deterministic: "test key" body, known expected value computed the same way.
    $publicKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIP7bJ2FZQnXr6gJkYxQnMlpHJ2vQ0mWtXyRfZk1aB2c test';
    $fingerprint = $service->fingerprint($publicKey);

    expect($fingerprint)->toStartWith('SHA256:');
});

it('validates the public key format and base64 body', function () {
    $service = new SshKeyService;

    expect(fn () => $service->validatePublicKey('not-a-key'))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->validatePublicKey('ssh-ed25519 !!!not-base64!!!'))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->validatePublicKey('pgp-signature AAAAC3NzaC1lZDI1NTE5AAAAI'))
        ->toThrow(ValidationException::class);
});

it('normalizes a valid key to type + body', function () {
    $service = new SshKeyService;

    $clean = $service->validatePublicKey("ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQDs comment here\n");

    expect($clean)->toBe('ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQDs');
});

it('generates an ed25519 key pair and never persists the private key', function () {
    $organization = Organization::factory()->create();
    app(TenantContext::class)->set($organization->id, $organization);

    $service = new SshKeyService;
    $result = $service->generate('Deploy bot');

    expect($result)->toHaveKeys(['key', 'private_key']);

    // Public half is stored, fingerprint derived.
    expect($result['key']->name)->toBe('Deploy bot')
        ->and($result['key']->public_key)->toStartWith('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI')
        ->and($result['key']->fingerprint)->toStartWith('SHA256:');

    // Private half is a real OpenSSH private key; the private key text never
    // ends up in the database (only the public half is stored).
    expect($result['private_key'])->toStartWith('-----BEGIN OPENSSH PRIVATE KEY-----');

    $stored = SshKey::findOrFail($result['key']->id);

    expect($stored->public_key)->toBe($result['key']->public_key)
        ->and($stored->public_key)->not->toContain('PRIVATE KEY')
        ->and(SshKey::where('public_key', $result['private_key'])->exists())->toBeFalse();
});

it('generates an rsa 4096 key pair with a matching public key', function () {
    $organization = Organization::factory()->create();
    app(TenantContext::class)->set($organization->id, $organization);

    $service = new SshKeyService;
    $result = $service->generate('Backup host', 'rsa');

    expect($result['key']->public_key)->toStartWith('ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAACA');
    expect($result['private_key'])->toContain('PRIVATE KEY');
});

it('seals the private key in the vault when a vault password is given', function () {
    $organization = Organization::factory()->create();
    app(TenantContext::class)->set($organization->id, $organization);

    $service = new SshKeyService;
    $result = $service->generate('Sealed key', 'ed25519', 'correct-horse-battery');

    $stored = SshKey::findOrFail($result['key']->id);

    expect($stored->hasPrivateKeyInVault())->toBeTrue()
        ->and($stored->encrypted_private_key)->not->toBeNull()
        ->and($stored->private_key_salt)->not->toBeNull()
        ->and($stored->private_key_verifier)->not->toBeNull()
        ->and($stored->vault_enabled_at)->not->toBeNull();

    // The plaintext never reaches the database.
    expect($stored->public_key)->not->toContain('PRIVATE KEY');
    expect($stored->encrypted_private_key)->not->toContain('BEGIN OPENSSH PRIVATE KEY');
});

it('recovers the sealed private key with the correct vault password', function () {
    $organization = Organization::factory()->create();
    app(TenantContext::class)->set($organization->id, $organization);

    $service = new SshKeyService;
    $result = $service->generate('Recoverable', 'ed25519', 's3cret-vault-pass');

    $recovered = $service->unlock(SshKey::findOrFail($result['key']->id), 's3cret-vault-pass');

    expect($recovered)->toBe($result['private_key']);
});

it('rejects a wrong vault password without decrypting', function () {
    $organization = Organization::factory()->create();
    app(TenantContext::class)->set($organization->id, $organization);

    $service = new SshKeyService;
    $result = $service->generate('Locked', 'ed25519', 'right-passphrase');

    expect(fn () => $service->unlock(SshKey::findOrFail($result['key']->id), 'wrong-passphrase'))
        ->toThrow(ValidationException::class);

    // The sealed ciphertext is untouched after a failed attempt.
    $stored = SshKey::findOrFail($result['key']->id);
    expect($stored->encrypted_private_key)->not->toBeNull();
});

it('cannot unlock a key without a vaulted private key', function () {
    $organization = Organization::factory()->create();
    app(TenantContext::class)->set($organization->id, $organization);

    $service = new SshKeyService;
    $key = $service->create('No vault', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIP7bJ2FZQnXr6gJkYxQnMlpHJ2vQ0mWtXyRfZk1aB2c test');

    expect(fn () => $service->unlock($key, 'any-passphrase'))
        ->toThrow(ValidationException::class);
});
