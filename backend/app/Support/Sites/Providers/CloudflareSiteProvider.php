<?php

namespace App\Support\Sites\Providers;

use App\Contracts\SiteProviderInterface;
use App\Support\Sites\SiteDeployFailedException;
use App\Support\Sites\SiteProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Real Cloudflare Pages provider behind SiteProviderInterface.
 *
 * Talks to the Cloudflare v4 API (api.cloudflare.com/client/v4) over HTTPS via
 * the Laravel HTTP client (Guzzle), authenticated with an API token scoped to
 * the account ("Account / Cloudflare Pages / Edit").
 *
 * Mapping to the interface:
 *   - provision → POST /accounts/{id}/pages/projects  (Pages project, the
 *     provider_site_id is the project name, url is its {name}.pages.dev
 *     subdomain)
 *   - deploy    → POST .../projects/{name}/deployments (build trigger for a
 *     git-connected project; the deployment id is the revision — Pages does
 *     not expose git SHAs)
 *   - rollback  → POST .../deployments/{id}/retry       (re-runs a previous
 *     deployment, the closest Pages equivalent to a rollback)
 *   - delete    → DELETE .../projects/{name}
 *
 * Without CLOUDFLARE_API_TOKEN / CLOUDFLARE_ACCOUNT_ID every call throws, so
 * registering the provider is always safe; the sandbox remains the default.
 */
final class CloudflareSiteProvider implements SiteProviderInterface
{
    private const TIMEOUT_SECONDS = 30;

    public function name(): string
    {
        return 'cloudflare';
    }

    public function label(): string
    {
        return 'Cloudflare Pages';
    }

    public function isConfigured(): bool
    {
        return $this->apiToken() !== '' && $this->accountId() !== '';
    }

    /** @throws SiteProviderException */
    public function provision(string $name, string $framework, string $gitUrl, string $gitBranch): array
    {
        $this->guardConfigured();

        $response = $this->send('post', "/accounts/{$this->accountId()}/pages/projects", [
            'name' => $name,
            'production_branch' => $gitBranch !== '' ? $gitBranch : config('cloudflare.sites.production_branch', 'main'),
        ]);

        $project = $response->json('result');

        return [
            'provider_site_id' => (string) ($project['name'] ?? $name),
            'url' => 'https://'.($project['subdomain'] ?? $name.'.pages.dev'),
        ];
    }

    /** @throws SiteDeployFailedException */
    public function deploy(string $providerSiteId, string $gitUrl, string $gitBranch, array $environment): array
    {
        $this->guardConfigured();

        $response = $this->send('post', "/accounts/{$this->accountId()}/pages/projects/{$providerSiteId}/deployments", [
            'branch' => $gitBranch,
        ], true);

        $deployment = $response->json('result') ?? [];

        return [
            // Pages deployments are identified by their id (no git SHA).
            'commit_sha' => substr((string) ($deployment['id'] ?? ''), 0, 12),
            'url' => (string) ($deployment['url'] ?? ''),
            'logs' => $this->buildLogs($deployment, $gitBranch),
        ];
    }

    /** @throws SiteProviderException */
    public function rollback(string $providerSiteId, string $commitSha): array
    {
        $this->guardConfigured();

        $response = $this->send('post', "/accounts/{$this->accountId()}/pages/projects/{$providerSiteId}/deployments/{$commitSha}/retry");

        return ['url' => (string) ($response->json('result.url') ?? '')];
    }

    /** @throws SiteProviderException */
    public function preview(string $providerSiteId, string $commitSha): array
    {
        $this->guardConfigured();

        // Every Pages deployment has its own preview URL (and aliases).
        $response = $this->send('get', "/accounts/{$this->accountId()}/pages/projects/{$providerSiteId}/deployments/{$commitSha}");

        $deployment = $response->json('result') ?? [];
        $url = (string) ($deployment['url'] ?? '');

        if ($url === '') {
            $url = 'https://'.$providerSiteId.'.pages.dev';
        }

        return [
            'url' => $url,
            'aliases' => array_values(array_filter(array_map(
                static fn (mixed $alias): string => (string) $alias,
                (array) ($deployment['aliases'] ?? []),
            ))),
        ];
    }

    /** @throws SiteProviderException */
    public function delete(string $providerSiteId): void
    {
        $this->guardConfigured();

        $this->send('delete', "/accounts/{$this->accountId()}/pages/projects/{$providerSiteId}");
    }

    /**
     * @param  array<string, mixed>  $deployment
     */
    private function buildLogs(array $deployment, string $branch): string
    {
        $lines = [
            '[cloudflare-pages] deploying branch '.$branch,
        ];

        foreach ((array) ($deployment['stages'] ?? []) as $stage) {
            $lines[] = sprintf(
                '[cloudflare-pages] %s: %s',
                (string) ($stage['name'] ?? 'build'),
                (string) ($stage['status'] ?? 'unknown'),
            );
        }

        if (($deployment['environment'] ?? '') !== '') {
            $lines[] = '[cloudflare-pages] environment: '.$deployment['environment'];
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws SiteProviderException|SiteDeployFailedException
     */
    private function send(string $method, string $path, array $payload = [], bool $deploy = false): Response
    {
        try {
            $request = Http::timeout(self::TIMEOUT_SECONDS)
                ->withToken($this->apiToken())
                ->acceptJson()
                ->baseUrl((string) config('cloudflare.endpoint', 'https://api.cloudflare.com/client/v4'));

            $response = $request->send($method, $path, $method === 'get' ? ['query' => $payload] : ['json' => $payload]);
        } catch (ConnectionException $e) {
            throw new SiteProviderException("Cloudflare Pages is unreachable: {$e->getMessage()}");
        }

        if ($response->failed()) {
            $this->throwUpstream($response, $deploy, $method, $path);
        }

        if (! $response->json('success')) {
            $this->throwUpstream($response, $deploy, $method, $path);
        }

        return $response;
    }

    /**
     * @throws SiteProviderException|SiteDeployFailedException
     */
    private function throwUpstream(Response $response, bool $deploy, string $method, string $path): never
    {
        $errors = (array) ($response->json('errors') ?? []);
        $messages = array_map(
            static fn (mixed $error): string => (string) (is_array($error) ? ($error['message'] ?? '') : $error),
            $errors,
        );
        $message = implode(' ', array_filter($messages));

        if ($message === '') {
            $message = "Cloudflare Pages failed [{$method} {$path}] with HTTP {$response->status()}.";
        }

        $exception = $deploy
            ? new SiteDeployFailedException($message, $message."\n".(string) $response->body())
            : new SiteProviderException($message);

        throw $exception;
    }

    /** @throws SiteProviderException */
    private function guardConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new SiteProviderException('Cloudflare Pages provider is not configured (missing API token and/or account id).');
        }
    }

    private function apiToken(): string
    {
        return (string) config('cloudflare.api_token');
    }

    private function accountId(): string
    {
        return (string) config('cloudflare.account_id');
    }
}
