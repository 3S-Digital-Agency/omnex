<?php

use App\Models\SocialAccount;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function socialState(string $url): string
{
    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return (string) ($query['state'] ?? '');
}

function socialCompletionCode(string $location): string
{
    $query = [];
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return (string) ($query['code'] ?? '');
}

it('lists configured and unconfigured providers', function () {
    $this->getJson('/api/v1/auth/social/providers')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'sandbox')
        ->assertJsonPath('data.0.configured', true)
        ->assertJsonPath('data.1.name', 'google')
        ->assertJsonPath('data.1.configured', false);
});

it('returns an authorization url when starting a redirect', function () {
    $response = $this->getJson('/api/v1/auth/sandbox/redirect');

    $response->assertOk()->assertJsonStructure(['url']);
    expect($response->json('url'))->toContain('/api/v1/auth/sandbox/callback?');
});

it('registers a brand-new user through the sandbox provider', function () {
    $state = socialState($this->getJson('/api/v1/auth/sandbox/redirect')->json('url'));

    $callback = $this->get("/api/v1/auth/sandbox/callback?code=newuser@example.com&state={$state}");

    $callback->assertRedirect();
    expect($callback->headers->get('Location'))->toContain('/social/callback?code=');

    $complete = $this->postJson('/api/v1/auth/social/complete', [
        'code' => socialCompletionCode($callback->headers->get('Location')),
    ]);

    $complete->assertOk()
        ->assertJsonStructure(['token', 'user'])
        ->assertJsonPath('user.email', 'newuser@example.com');

    expect(User::where('email', 'newuser@example.com')->exists())->toBeTrue();
    expect(SocialAccount::where('provider', 'sandbox')->where('provider_email', 'newuser@example.com')->exists())->toBeTrue();
});

it('links a returning user through a verified email match', function () {
    $user = User::factory()->create(['email' => 'alice@example.com']);

    $state = socialState($this->getJson('/api/v1/auth/sandbox/redirect')->json('url'));
    $callback = $this->get("/api/v1/auth/sandbox/callback?code=alice@example.com&state={$state}");

    $complete = $this->postJson('/api/v1/auth/social/complete', [
        'code' => socialCompletionCode($callback->headers->get('Location')),
    ]);

    $complete->assertOk()->assertJsonPath('user.id', $user->id);
    expect(SocialAccount::where('user_id', $user->id)->where('provider', 'sandbox')->exists())->toBeTrue();
});

it('links a provider to an already authenticated user', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $state = socialState($this->getJson('/api/v1/auth/sandbox/redirect?link=1')->json('url'));

    $this->get("/api/v1/auth/sandbox/callback?code=linkme@example.com&state={$state}")->assertRedirect();

    expect(SocialAccount::where('user_id', $user->id)->where('provider', 'sandbox')->exists())->toBeTrue();
});

it('lists and unlinks a user social accounts', function () {
    $user = User::factory()->create();
    $user->socialAccounts()->create([
        'provider' => 'sandbox',
        'provider_user_id' => 'sandbox-test',
        'provider_email' => 'alice@example.com',
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/auth/social/accounts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.provider', 'sandbox');

    $this->deleteJson('/api/v1/auth/social/accounts/sandbox')->assertOk();
    expect($user->socialAccounts()->count())->toBe(0);
});

it('rejects an unconfigured real provider', function () {
    $this->getJson('/api/v1/auth/google/redirect')->assertStatus(422);
});

it('rejects an unknown provider', function () {
    $this->getJson('/api/v1/auth/doesnotexist/redirect')->assertStatus(422);
});

it('rejects an invalid or expired state', function () {
    $this->get('/api/v1/auth/sandbox/callback?code=demo@omnex.cloud&state=forged')->assertStatus(422);
});
