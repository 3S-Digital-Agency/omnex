<?php

use App\Support\SocialAuth\Providers\GitHubSocialProvider;
use App\Support\SocialAuth\Providers\OpenAISocialProvider;
use Illuminate\Support\Facades\Http;

function githubConfigure(): void
{
    config()->set('socialauth.github', [
        'client_id' => 'gh-client',
        'client_secret' => 'gh-secret',
        'redirect' => 'http://localhost:8000/api/v1/auth/github/callback',
    ]);
}

function openaiConfigure(): void
{
    config()->set('socialauth.openai', [
        'client_id' => 'oa-client',
        'client_secret' => 'oa-secret',
        'redirect' => 'http://localhost:8000/api/v1/auth/openai/callback',
    ]);
}

it('lists github and openai among the providers', function () {
    $providers = collect($this->getJson('/api/v1/auth/social/providers')->json('data'));

    expect($providers->pluck('name')->toArray())
        ->toContain('github', 'openai')
        ->and($providers->firstWhere('name', 'github')['configured'])->toBeFalse()
        ->and($providers->firstWhere('name', 'openai')['configured'])->toBeFalse();
});

it('keeps github unconfigured until credentials are set', function () {
    expect(app(GitHubSocialProvider::class)->isConfigured())->toBeFalse();
    $this->getJson('/api/v1/auth/github/redirect')->assertStatus(422);

    githubConfigure();
    expect(app(GitHubSocialProvider::class)->isConfigured())->toBeTrue();
});

it('builds the github authorize url', function () {
    githubConfigure();

    $url = app(GitHubSocialProvider::class)->redirectUrl('state-123');

    expect($url)
        ->toContain('github.com/login/oauth/authorize')
        ->toContain('client_id=gh-client')
        ->toContain('scope=read%3Auser+user%3Aemail')
        ->toContain('state=state-123');
});

it('exchanges a github code and resolves the primary verified email', function () {
    githubConfigure();

    Http::fake([
        'github.com/login/oauth/access_token' => Http::response([
            'access_token' => 'gh-at-123',
        ]),
        'api.github.com/user' => Http::response([
            'id' => 42,
            'login' => 'octocat',
            'name' => 'Mona Lisa',
            'email' => null,
            'avatar_url' => 'https://avatars.example.com/u/42',
        ]),
        'api.github.com/user/emails' => Http::response([
            ['email' => 'private@example.com', 'primary' => true, 'verified' => true],
            ['email' => 'old@example.com', 'primary' => false, 'verified' => true],
        ]),
    ]);

    $user = app(GitHubSocialProvider::class)->userFromCode('code-123');

    expect($user->id)->toBe('42')
        ->and($user->email)->toBe('private@example.com')
        ->and($user->name)->toBe('Mona Lisa')
        ->and($user->emailVerified)->toBeTrue()
        ->and($user->avatarUrl)->toBe('https://avatars.example.com/u/42');
});

it('keeps openai unconfigured until credentials are set', function () {
    expect(app(OpenAISocialProvider::class)->isConfigured())->toBeFalse();
    $this->getJson('/api/v1/auth/openai/redirect')->assertStatus(422);

    openaiConfigure();
    expect(app(OpenAISocialProvider::class)->isConfigured())->toBeTrue();
});

it('builds the openai authorize url', function () {
    openaiConfigure();

    $url = app(OpenAISocialProvider::class)->redirectUrl('state-123');

    expect($url)
        ->toContain('auth.openai.com/api/accounts/authorize')
        ->toContain('client_id=oa-client')
        ->toContain('scope=openid+profile+email')
        ->toContain('state=state-123');
});

it('exchanges an openai code via the oidc userinfo endpoint', function () {
    openaiConfigure();

    Http::fake([
        'auth.openai.com/api/accounts/oauth/token' => Http::response([
            'access_token' => 'oa-at-123',
        ]),
        'auth.openai.com/api/accounts/oauth/userinfo' => Http::response([
            'sub' => 'user-abc',
            'email' => 'ada@example.com',
            'name' => 'Ada',
            'picture' => 'https://avatars.example.com/ada.png',
            'email_verified' => true,
        ]),
    ]);

    $user = app(OpenAISocialProvider::class)->userFromCode('code-123');

    expect($user->id)->toBe('user-abc')
        ->and($user->email)->toBe('ada@example.com')
        ->and($user->name)->toBe('Ada')
        ->and($user->emailVerified)->toBeTrue()
        ->and($user->avatarUrl)->toBe('https://avatars.example.com/ada.png');
});

it('completes a full github sign-in through the controller', function () {
    githubConfigure();

    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gh-at']),
        'api.github.com/user' => Http::response([
            'id' => 42,
            'login' => 'octocat',
            'name' => 'Mona Lisa',
            'email' => 'mona@example.com',
        ]),
    ]);

    $state = socialState($this->getJson('/api/v1/auth/github/redirect')->json('url'));
    $callback = $this->get("/api/v1/auth/github/callback?code=realcode&state={$state}");
    $callback->assertRedirect();

    $complete = $this->postJson('/api/v1/auth/social/complete', [
        'code' => socialCompletionCode($callback->headers->get('Location')),
    ]);

    $complete->assertOk()->assertJsonPath('user.email', 'mona@example.com');
});
