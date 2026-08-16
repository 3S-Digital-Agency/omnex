<?php

use App\Support\SocialAuth\Providers\SdpSocialProvider;
use Illuminate\Support\Facades\Http;

function sdpConfigure(): void
{
    config()->set('socialauth.sdp', [
        'base_url' => 'https://cloud.serveursdupeuple.net',
        'client_id' => 'test-client',
        'client_secret' => 'test-secret',
        'redirect' => 'http://localhost:8000/api/v1/auth/sdp/callback',
    ]);
}

function sdpState(string $url): string
{
    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return (string) ($query['state'] ?? '');
}

function sdpCompletionCode(string $location): string
{
    $query = [];
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return (string) ($query['code'] ?? '');
}

it('stays unconfigured until credentials are set', function () {
    expect(app(SdpSocialProvider::class)->isConfigured())->toBeFalse();

    $this->getJson('/api/v1/auth/sdp/redirect')->assertStatus(422);
});

it('builds the Nextcloud authorize url', function () {
    sdpConfigure();

    $url = app(SdpSocialProvider::class)->redirectUrl('state-123');

    expect($url)
        ->toContain('cloud.serveursdupeuple.net/index.php/apps/oauth2/authorize')
        ->toContain('client_id=test-client')
        ->toContain('response_type=code')
        ->toContain('state=state-123');
});

it('exchanges a code for an identity via the OCS endpoint', function () {
    sdpConfigure();

    Http::fake([
        '*apps/oauth2/api/v1/token*' => Http::response([
            'access_token' => 'at-123',
            'user_id' => 'alice',
        ]),
        '*ocs/v2.php/cloud/user*' => Http::response([
            'ocs' => ['data' => [
                'id' => 'alice',
                'email' => 'alice@serveursdupeuple.net',
                'displayname' => 'Alice',
            ]],
        ]),
    ]);

    $user = app(SdpSocialProvider::class)->userFromCode('code-123');

    expect($user->id)->toBe('alice')
        ->and($user->email)->toBe('alice@serveursdupeuple.net')
        ->and($user->name)->toBe('Alice')
        ->and($user->emailVerified)->toBeFalse();
});

it('falls back to display-name and keeps email unverified', function () {
    sdpConfigure();

    Http::fake([
        '*apps/oauth2/api/v1/token*' => Http::response(['access_token' => 'at', 'user_id' => 'bob']),
        '*ocs/v2.php/cloud/user*' => Http::response([
            'ocs' => ['data' => [
                'id' => 'bob',
                'email' => 'bob@serveursdupeuple.net',
                'display-name' => 'Bob',
            ]],
        ]),
    ]);

    $user = app(SdpSocialProvider::class)->userFromCode('code');

    expect($user->name)->toBe('Bob')
        ->and($user->id)->toBe('bob')
        ->and($user->emailVerified)->toBeFalse();
});

it('completes a full sign-in through the controller', function () {
    sdpConfigure();

    Http::fake([
        '*apps/oauth2/api/v1/token*' => Http::response(['access_token' => 'at', 'user_id' => 'alice']),
        '*ocs/v2.php/cloud/user*' => Http::response([
            'ocs' => ['data' => [
                'id' => 'alice',
                'email' => 'alice@serveursdupeuple.net',
                'displayname' => 'Alice',
            ]],
        ]),
    ]);

    $state = sdpState($this->getJson('/api/v1/auth/sdp/redirect')->json('url'));

    $callback = $this->get("/api/v1/auth/sdp/callback?code=realcode&state={$state}");
    $callback->assertRedirect();

    $complete = $this->postJson('/api/v1/auth/social/complete', [
        'code' => sdpCompletionCode($callback->headers->get('Location')),
    ]);

    $complete->assertOk()
        ->assertJsonPath('user.email', 'alice@serveursdupeuple.net');
});
