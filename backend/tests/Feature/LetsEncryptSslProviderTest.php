<?php

use App\Contracts\DnsProviderInterface;
use App\Models\Domain;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\SslCertificate;
use App\Models\User;
use App\Support\Domains\DnsProviderRegistry;
use App\Support\Ssl\Acme\AcmeClient;
use App\Support\Ssl\Providers\LetsEncryptSslProvider;
use App\Support\Ssl\SslProviderException;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    config()->set('letsencrypt.directory', 'https://acme.test/directory');
    config()->set('letsencrypt.email', 'admin@example.com');
    config()->set('letsencrypt.account_key_path', sys_get_temp_dir().'/omnex-acme-'.bin2hex(random_bytes(6)).'.pem');
    config()->set('letsencrypt.poll_interval_ms', 0);
    config()->set('letsencrypt.poll_attempts', 10);
});

function leSslContext(string $tier = 'pro'): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create(['plan_tier' => $tier]);

    Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role_id' => Role::where('key', 'owner')->firstOrFail()->id,
        'status' => 'active',
    ]);

    Sanctum::actingAs($user);

    return [$user, $organization];
}

/** A DnsProviderInterface spy that records TXT record placements. */
function leDnsSpy(): DnsProviderInterface
{
    return new class implements DnsProviderInterface
    {
        /** @var array<int, array{domain: string, record: array<string, mixed>}> */
        public array $created = [];

        /** @var array<int, array{domain: string, record: array<string, mixed>}> */
        public array $deleted = [];

        public function name(): string
        {
            return 'test-dns';
        }

        public function label(): string
        {
            return 'Test DNS';
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function createZone(string $domain): array
        {
            return ['external_id' => 'zone-1'];
        }

        public function deleteZone(string $domain): array
        {
            return [];
        }

        public function createRecord(string $domain, array $record): array
        {
            $this->created[] = ['domain' => $domain, 'record' => $record];

            return ['external_id' => 'record-1'];
        }

        public function updateRecord(string $domain, array $record): array
        {
            return [];
        }

        public function deleteRecord(string $domain, array $record): array
        {
            $this->deleted[] = ['domain' => $domain, 'record' => $record];

            return [];
        }

        public function enableDnssec(string $domain): array
        {
            return [];
        }

        public function disableDnssec(string $domain): array
        {
            return [];
        }
    };
}

/** A real (self-signed) certificate PEM so openssl_x509_parse can read the validity. */
function leTestCertPem(string $domain = 'example.com'): string
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);

    $cnf = tempnam(sys_get_temp_dir(), 'omnex-cnf-').'.cnf';
    file_put_contents($cnf, "[req]\ndistinguished_name = dn\nprompt = no\n[dn]\nCN = {$domain}\n");

    $config = ['config' => $cnf, 'digest_alg' => 'sha256'];

    $csr = openssl_csr_new(['commonName' => $domain], $key, $config);
    $cert = openssl_csr_sign($csr, null, $key, 90, $config);
    openssl_x509_export($cert, $pem);

    @unlink($cnf);

    return $pem;
}

/** Fakes the full ACME v2 flow (directory → account → order → authz → finalize → cert). */
function leFakeAcme(string $pem): callable
{
    return function (Request $request) use ($pem) {
        static $authzCalls = 0;

        $url = $request->url();
        $method = $request->method();

        if ($url === 'https://acme.test/directory') {
            return Http::response([
                'newNonce' => 'https://acme.test/new-nonce',
                'newAccount' => 'https://acme.test/new-account',
                'newOrder' => 'https://acme.test/new-order',
                'revokeCert' => 'https://acme.test/revoke',
            ]);
        }

        if ($method === 'HEAD' && $url === 'https://acme.test/new-nonce') {
            return Http::response('', 200, ['Replay-Nonce' => 'nonce-head']);
        }

        if ($url === 'https://acme.test/new-account') {
            return Http::response([], 201, ['Location' => 'https://acme.test/acct/1', 'Replay-Nonce' => 'nonce-1']);
        }

        if ($url === 'https://acme.test/new-order') {
            return Http::response([
                'status' => 'pending',
                'authorizations' => ['https://acme.test/authz/1'],
                'finalize' => 'https://acme.test/finalize/1',
            ], 201, ['Location' => 'https://acme.test/order/1', 'Replay-Nonce' => 'nonce-2']);
        }

        if ($url === 'https://acme.test/authz/1') {
            $authzCalls++;

            if ($authzCalls === 1) {
                return Http::response([
                    'status' => 'pending',
                    'identifier' => ['type' => 'dns', 'value' => 'example.com'],
                    'challenges' => [['type' => 'dns-01', 'url' => 'https://acme.test/challenge/1', 'token' => 'tok-123']],
                ], 200, ['Replay-Nonce' => 'nonce-3']);
            }

            return Http::response(['status' => 'valid'], 200, ['Replay-Nonce' => 'nonce-4']);
        }

        if ($url === 'https://acme.test/challenge/1') {
            return Http::response(['status' => 'pending'], 200, ['Replay-Nonce' => 'nonce-5']);
        }

        if ($url === 'https://acme.test/finalize/1') {
            return Http::response(['status' => 'processing'], 200, ['Location' => 'https://acme.test/order/1', 'Replay-Nonce' => 'nonce-6']);
        }

        if ($url === 'https://acme.test/order/1') {
            return Http::response(['status' => 'valid', 'certificate' => 'https://acme.test/cert/1'], 200, ['Replay-Nonce' => 'nonce-7']);
        }

        if ($url === 'https://acme.test/cert/1') {
            return Http::response($pem, 200, ['Replay-Nonce' => 'nonce-8']);
        }

        return Http::response(['type' => 'urn:ietf:params:acme:error:serverInternal', 'detail' => "unexpected {$url}"], 500);
    };
}

it('issues a certificate through the ACME v2 flow and cleans up the TXT record', function () {
    $pem = leTestCertPem();

    $accountKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    openssl_pkey_export($accountKey, $accountKeyPem);

    config()->set('letsencrypt.certificate_key_bits', 2048);

    Http::fake(leFakeAcme($pem));

    $solved = [];
    $cleanups = 0;

    $client = new AcmeClient('https://acme.test/directory', $accountKeyPem, 'admin@example.com', true, 0, 10);

    $result = $client->issue(['example.com'], function (string $domain, string $value) use (&$solved, &$cleanups) {
        $solved[] = [$domain, $value];

        return function () use (&$cleanups) {
            $cleanups++;
        };
    });

    expect($result['fullchain'])->toContain('BEGIN CERTIFICATE');
    expect($result['certificate_url'])->toBe('https://acme.test/cert/1');
    expect($solved)->toHaveCount(1);
    expect($solved[0][0])->toBe('example.com');
    // base64url(SHA-256(keyAuthorization)) is 43 chars.
    expect(strlen($solved[0][1]))->toBe(43);
    expect($cleanups)->toBe(1);
});

it('places and removes the dns-01 TXT record through the tenant DNS provider', function () {
    $pem = leTestCertPem();
    Http::fake(leFakeAcme($pem));

    $dns = leDnsSpy();
    app(DnsProviderRegistry::class)->register($dns);
    config()->set('omnex.domain.dns_provider', 'test-dns');

    $result = (new LetsEncryptSslProvider)->issue('example.com');

    expect($result['issuer'])->toBe("Let's Encrypt");
    expect($result['status'])->toBe('active');
    expect($result['certificate_pem'])->toContain('BEGIN CERTIFICATE');
    expect($result['expires_at'])->not->toBeNull();

    expect($dns->created)->toHaveCount(1);
    expect($dns->created[0]['record']['type'])->toBe('TXT');
    expect($dns->created[0]['record']['name'])->toBe('_acme-challenge.example.com');

    expect($dns->deleted)->toHaveCount(1);
    expect($dns->deleted[0]['record']['name'])->toBe('_acme-challenge.example.com');
});

it('is unconfigured until an ACME contact e-mail is set', function () {
    config()->set('letsencrypt.email', '');

    expect((new LetsEncryptSslProvider)->isConfigured())->toBeFalse();

    (new LetsEncryptSslProvider)->issue('example.com');
})->throws(SslProviderException::class, 'OMNEX_LETSENCRYPT_EMAIL');

it('requires the stored chain to revoke a certificate', function () {
    config()->set('letsencrypt.email', 'admin@example.com');

    (new LetsEncryptSslProvider)->revoke('example.com', ['external_id' => 'https://acme.test/cert/1']);
})->throws(SslProviderException::class, 'stored certificate chain');

it('persists the issued chain and real validity through SslService', function () {
    [$user, $organization] = leSslContext('pro');

    $organization->update(['settings' => ['ssl_provider' => 'letsencrypt', 'dns_provider' => 'test-dns']]);

    $domain = Domain::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'example.com',
    ]);

    $pem = leTestCertPem();
    Http::fake(leFakeAcme($pem));

    $dns = leDnsSpy();
    app(DnsProviderRegistry::class)->register($dns);

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/ssl")
        ->assertStatus(201)
        ->assertJsonPath('provider', 'letsencrypt')
        ->assertJsonPath('issuer', "Let's Encrypt")
        ->assertJsonPath('status', 'active');

    $certificate = SslCertificate::where('domain_id', $domain->id)->firstOrFail();

    expect($certificate->certificate_pem)->toContain('BEGIN CERTIFICATE');
    expect($certificate->expires_at)->not->toBeNull();
});
