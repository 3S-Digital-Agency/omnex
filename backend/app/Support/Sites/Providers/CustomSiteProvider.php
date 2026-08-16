<?php

namespace App\Support\Sites\Providers;

use App\Contracts\SiteProviderInterface;
use App\Support\Sites\SiteDeployFailedException;
use App\Support\Sites\SiteProviderException;
use Illuminate\Support\Facades\Http;

/**
 * Bring-your-own hosting platform behind SiteProviderInterface.
 *
 * Points at any HTTP/JSON gateway the operator configures
 * (config/customsites.php). Requests are POSTed as JSON with an optional
 * Bearer token; the gateway replies with the same shapes the interface
 * expects under a `data` key:
 *
 *   {"command": "provision", "name": "…", "framework": "…", …}
 *   → {"data": {"provider_site_id": "…", "url": "…"}}
 *
 * Commands: provision, deploy, rollback, delete. A failed deploy must return
 * an HTTP error with {"error": "…", "logs": "…"}.
 */
final class CustomSiteProvider implements SiteProviderInterface
{
    public function name(): string
    {
        return 'custom';
    }

    public function label(): string
    {
        return 'Custom';
    }

    public function isConfigured(): bool
    {
        return $this->endpoint() !== '';
    }

    public function provision(string $name, string $framework, string $gitUrl, string $gitBranch): array
    {
        return $this->call('provision', [
            'name' => $name,
            'framework' => $framework,
            'git_url' => $gitUrl,
            'git_branch' => $gitBranch,
        ]);
    }

    public function deploy(string $providerSiteId, string $gitUrl, string $gitBranch, array $environment): array
    {
        return $this->call('deploy', [
            'provider_site_id' => $providerSiteId,
            'git_url' => $gitUrl,
            'git_branch' => $gitBranch,
            'environment' => $environment,
        ]);
    }

    public function rollback(string $providerSiteId, string $commitSha): array
    {
        return $this->call('rollback', [
            'provider_site_id' => $providerSiteId,
            'commit_sha' => $commitSha,
        ]);
    }

    public function delete(string $providerSiteId): void
    {
        $this->call('delete', ['provider_site_id' => $providerSiteId]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $command, array $payload): array
    {
        if (! $this->isConfigured()) {
            throw new SiteProviderException('Custom sites provider is not configured (missing endpoint).');
        }

        $response = Http::timeout(30)
            ->withHeaders(['Accept' => 'application/json'])
            ->when($this->apiKey() !== '', fn ($http) => $http->withToken($this->apiKey()))
            ->post($this->endpoint(), array_merge(['command' => $command], $payload));

        if ($response->failed()) {
            $json = $response->json();
            $message = $json['error'] ?? "Custom sites provider failed with HTTP {$response->status()}.";

            if ($command === 'deploy') {
                throw new SiteDeployFailedException((string) $message, (string) ($json['logs'] ?? ''));
            }

            throw new SiteProviderException((string) $message);
        }

        $json = $response->json();

        if (is_array($json['data'] ?? null)) {
            return $json['data'];
        }

        if (is_array($json)) {
            return $json;
        }

        throw new SiteProviderException('Custom sites provider returned an invalid response.');
    }

    private function endpoint(): string
    {
        return rtrim((string) config('customsites.endpoint'), '/');
    }

    private function apiKey(): string
    {
        return (string) config('customsites.api_key');
    }
}
