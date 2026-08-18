<?php

use App\Support\Sites\Providers\CloudflareSiteProvider;
use App\Support\Sites\SiteDeployFailedException;
use App\Support\Sites\SiteProviderException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('cloudflare.endpoint', 'https://api.cloudflare.com/client/v4');
    config()->set('cloudflare.api_token', 'test-token-123');
    config()->set('cloudflare.account_id', 'acct-1');
    config()->set('cloudflare.sites.production_branch', 'main');
});

/** Path (no query, endpoint prefix stripped) of a fake HTTP request. */
function cloudflareSitePath(Request $request): string
{
    $path = parse_url($request->url(), PHP_URL_PATH) ?? '';

    return preg_replace('#^/client/v4#', '', $path) ?: $path;
}

it('is configured only when token and account id are set', function () {
    expect((new CloudflareSiteProvider)->isConfigured())->toBeTrue();

    config()->set('cloudflare.api_token', '');

    expect((new CloudflareSiteProvider)->isConfigured())->toBeFalse();
});

it('rejects calls before credentials are set', function () {
    config()->set('cloudflare.api_token', '');

    (new CloudflareSiteProvider)->provision('blog', 'static', 'https://github.com/acme/blog.git', 'main');
})->throws(SiteProviderException::class, 'not configured');

it('provisions a Pages project and returns its subdomain', function () {
    Http::fake(function (Request $request) {
        if (cloudflareSitePath($request) === '/accounts/acct-1/pages/projects' && $request->method() === 'POST') {
            return Http::response([
                'success' => true, 'errors' => [], 'messages' => [],
                'result' => ['name' => 'blog', 'subdomain' => 'blog.pages.dev'],
            ]);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'unexpected']], 'result' => null], 400);
    });

    expect((new CloudflareSiteProvider)->provision('blog', 'static', 'https://github.com/acme/blog.git', 'main'))
        ->toBe(['provider_site_id' => 'blog', 'url' => 'https://blog.pages.dev']);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && cloudflareSitePath($request) === '/accounts/acct-1/pages/projects'
        && $request['name'] === 'blog'
        && $request['production_branch'] === 'main'
        && $request->hasHeader('Authorization', 'Bearer test-token-123'));
});

it('uses the git branch as the production branch when provided', function () {
    Http::fake(function (Request $request) {
        if ($request->method() === 'POST' && str_contains($request->url(), '/pages/projects')) {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => ['name' => 'docs', 'subdomain' => 'docs.pages.dev']]);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'unexpected']], 'result' => null], 400);
    });

    (new CloudflareSiteProvider)->provision('docs', 'vite', 'https://github.com/acme/docs.git', 'prod');

    Http::assertSent(fn (Request $request) => $request['production_branch'] === 'prod');
});

it('deploys by triggering a build and returns the deployment revision', function () {
    Http::fake(function (Request $request) {
        if (cloudflareSitePath($request) === '/accounts/acct-1/pages/projects/blog/deployments' && $request->method() === 'POST') {
            return Http::response([
                'success' => true, 'errors' => [], 'messages' => [],
                'result' => [
                    'id' => 'deploy-abc123xyz', 'environment' => 'production',
                    'url' => 'https://deploy-abc123xyz.blog.pages.dev',
                    'stages' => [
                        ['name' => 'build', 'status' => 'success'],
                        ['name' => 'deploy', 'status' => 'success'],
                    ],
                ],
            ]);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'unexpected']], 'result' => null], 400);
    });

    $result = (new CloudflareSiteProvider)->deploy('blog', 'https://github.com/acme/blog.git', 'main', []);

    expect($result['commit_sha'])->toBe('deploy-abc12');
    expect($result['url'])->toBe('https://deploy-abc123xyz.blog.pages.dev');
    expect($result['logs'])->toContain('[cloudflare-pages] deploying branch main');
    expect($result['logs'])->toContain('build: success');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && cloudflareSitePath($request) === '/accounts/acct-1/pages/projects/blog/deployments'
        && $request['branch'] === 'main');
});

it('throws SiteDeployFailedException when the deployment build fails', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/deployments')) {
            return Http::response([
                'success' => false, 'errors' => [['message' => 'Build failed: missing install command']],
                'result' => null,
            ], 400);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'unexpected']], 'result' => null], 400);
    });

    (new CloudflareSiteProvider)->deploy('blog', 'https://github.com/acme/blog.git', 'main', []);
})->throws(SiteDeployFailedException::class, 'Build failed');

it('rolls back by retrying a previous deployment', function () {
    Http::fake(function (Request $request) {
        if (cloudflareSitePath($request) === '/accounts/acct-1/pages/projects/blog/deployments/old-deploy-1/retry' && $request->method() === 'POST') {
            return Http::response([
                'success' => true, 'errors' => [], 'messages' => [],
                'result' => ['id' => 'retry-9', 'url' => 'https://retry-9.blog.pages.dev'],
            ]);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'unexpected']], 'result' => null], 400);
    });

    expect((new CloudflareSiteProvider)->rollback('blog', 'old-deploy-1'))
        ->toBe(['url' => 'https://retry-9.blog.pages.dev']);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && cloudflareSitePath($request) === '/accounts/acct-1/pages/projects/blog/deployments/old-deploy-1/retry');
});

it('resolves the deployment preview url and aliases', function () {
    Http::fake(function (Request $request) {
        if (cloudflareSitePath($request) === '/accounts/acct-1/pages/projects/blog/deployments/deploy-abc123' && $request->method() === 'GET') {
            return Http::response([
                'success' => true, 'errors' => [], 'messages' => [],
                'result' => [
                    'id' => 'deploy-abc123',
                    'url' => 'https://deploy-abc123.blog.pages.dev',
                    'aliases' => ['https://staging.blog.pages.dev', 'https://deploy-abc123.blog.pages.dev'],
                ],
            ]);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'unexpected']], 'result' => null], 400);
    });

    expect((new CloudflareSiteProvider)->preview('blog', 'deploy-abc123'))
        ->toBe([
            'url' => 'https://deploy-abc123.blog.pages.dev',
            'aliases' => ['https://staging.blog.pages.dev', 'https://deploy-abc123.blog.pages.dev'],
        ]);

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && cloudflareSitePath($request) === '/accounts/acct-1/pages/projects/blog/deployments/deploy-abc123');
});

it('falls back to the project subdomain when a deployment has no url', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/deployments/ghost')) {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => ['id' => 'ghost']]);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'unexpected']], 'result' => null], 400);
    });

    expect((new CloudflareSiteProvider)->preview('blog', 'ghost'))
        ->toBe(['url' => 'https://blog.pages.dev', 'aliases' => []]);
});

it('deletes the Pages project', function () {
    Http::fake(function (Request $request) {
        if (cloudflareSitePath($request) === '/accounts/acct-1/pages/projects/blog' && $request->method() === 'DELETE') {
            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => ['id' => 'blog']]);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'unexpected']], 'result' => null], 400);
    });

    (new CloudflareSiteProvider)->delete('blog');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && cloudflareSitePath($request) === '/accounts/acct-1/pages/projects/blog');
});

it('throws SiteProviderException on upstream API errors', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/pages/projects/blog')) {
            return Http::response([
                'success' => false, 'errors' => [['message' => 'project not found']],
                'result' => null,
            ], 404);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'unexpected']], 'result' => null], 400);
    });

    (new CloudflareSiteProvider)->delete('blog');
})->throws(SiteProviderException::class, 'project not found');
