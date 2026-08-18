<?php

use App\Models\Domain;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\SslCertificate;
use App\Models\User;
use App\Support\Ssl\Providers\CloudflareSslProvider;
use App\Support\Ssl\SslProviderException;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    config()->set('cloudflare.endpoint', 'https://api.cloudflare.com/client/v4');
    config()->set('cloudflare.api_token', 'test-token-123');
    config()->set('cloudflare.account_id', 'acct-1');
});

function sslContext(string $tier = 'pro'): array
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

/** Path (endpoint prefix stripped) of a fake Cloudflare request. */
function cloudflareSslPath(Request $request): string
{
    $path = parse_url($request->url(), PHP_URL_PATH) ?? '';

    return preg_replace('#^/client/v4#', '', $path) ?: $path;
}

it('issues, renews and revokes a certificate through the sandbox provider', function () {
    [$user, $organization] = sslContext();

    $domain = Domain::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'example.com',
    ]);

    $issued = $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/ssl")
        ->assertStatus(201)
        ->assertJsonPath('provider', 'sandbox')
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('auto_renew', true);

    expect($issued->json('issued_at'))->not->toBeNull();
    expect($issued->json('expires_at'))->not->toBeNull();

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/ssl/renew")
        ->assertOk()
        ->assertJsonPath('status', 'active');

    $this->withHeader('X-Organization', $organization->id)
        ->deleteJson("/api/v1/domains/{$domain->id}/ssl")
        ->assertOk()
        ->assertJsonPath('status', 'revoked');

    expect(SslCertificate::where('domain_id', $domain->id)->firstOrFail()->status)->toBe('revoked');
});

it('lists certificates and reports the active SSL provider', function () {
    [$user, $organization] = sslContext();

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/ssl/providers')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'sandbox')
        ->assertJsonPath('data.1.name', 'cloudflare');

    $this->withHeader('X-Organization', $organization->id)
        ->getJson('/api/v1/ssl/provider')
        ->assertOk()
        ->assertJsonPath('data.name', 'sandbox');
});

it('gates TLS issuance behind the ssl feature flag', function () {
    [$user, $organization] = sslContext('free');

    $domain = Domain::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'example.com',
    ]);

    $this->withHeader('X-Organization', $organization->id)
        ->postJson("/api/v1/domains/{$domain->id}/ssl")
        ->assertStatus(403);

    expect(SslCertificate::where('domain_id', $domain->id)->exists())->toBeFalse();
});

it('auto-issues a certificate for a pro tier on domain registration, but not for free', function () {
    [$proUser, $proOrg] = sslContext('pro');
    [$freeUser, $freeOrg] = sslContext('free');

    // Free org: no auto-issued certificate.
    Sanctum::actingAs($freeUser);
    $freeDomain = $this->withHeader('X-Organization', $freeOrg->id)
        ->postJson('/api/v1/domains', ['domain' => 'freeexample-2026.com'])
        ->assertStatus(201)
        ->json('id');

    expect(SslCertificate::where('domain_id', $freeDomain)->exists())->toBeFalse();

    // Pro org: certificate is provisioned automatically.
    Sanctum::actingAs($proUser);
    $proDomain = $this->withHeader('X-Organization', $proOrg->id)
        ->postJson('/api/v1/domains', ['domain' => 'proexample-2026.com'])
        ->assertStatus(201)
        ->json('id');

    expect(SslCertificate::where('domain_id', $proDomain)->exists())->toBeTrue();
});

it('issues a Cloudflare edge certificate through the universal SSL API', function () {
    Http::fake(function (Request $request) {
        $path = cloudflareSslPath($request);

        if ($path === '/zones' && $request->method() === 'GET') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => [['id' => 'zone-42', 'name' => 'example.com']]]);
        }
        if ($path === '/zones/zone-42/ssl/universal/settings' && $request->method() === 'PATCH') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => ['enabled' => true, 'certificate_status' => 'active']]);
        }

        return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => []]);
    });

    $result = (new CloudflareSslProvider)->issue('example.com');

    expect($result['external_id'])->toBe('zone-42');
    expect($result['status'])->toBe('active');
    expect($result['issuer'])->toBe('Cloudflare');

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && cloudflareSslPath($request) === '/zones/zone-42/ssl/universal/settings'
        && json_decode($request->body(), true)['enabled'] === true);
});

it('rejects Cloudflare SSL calls before the token is set', function () {
    config()->set('cloudflare.api_token', '');

    (new CloudflareSslProvider)->issue('example.com');
})->throws(SslProviderException::class, 'CLOUDFLARE_API_TOKEN');

it('raises when Cloudflare has no zone for the domain', function () {
    Http::fake(fn () => Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => []]));

    (new CloudflareSslProvider)->issue('nosuch.example.com');
})->throws(SslProviderException::class, 'no zone');
