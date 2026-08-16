<?php

namespace App\Support\Sites\Providers;

use App\Contracts\SiteProviderInterface;
use App\Support\Sites\SiteDeployFailedException;
use Illuminate\Support\Str;

/**
 * Deterministic in-memory hosting platform for local/test environments.
 * Nothing is ever built or deployed anywhere — provisioning and deploys are
 * derived from a stable hash of the inputs, never random, so tests and the UI
 * are reproducible.
 *
 * Deploy failure is deterministic: a branch named "fail" makes the build
 * fail, which lets SiteService exercise its automatic-rollback path.
 */
final class SandboxSiteProvider implements SiteProviderInterface
{
    public function name(): string
    {
        return 'sandbox';
    }

    public function label(): string
    {
        return 'Sandbox';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function provision(string $name, string $framework, string $gitUrl, string $gitBranch): array
    {
        $slug = Str::slug($name);

        return [
            'provider_site_id' => 'sbox-site-'.$slug.'-'.substr(hash('sha256', $gitUrl), 0, 8),
            'url' => "https://{$slug}.omnex-sites.test",
        ];
    }

    public function deploy(string $providerSiteId, string $gitUrl, string $gitBranch, array $environment): array
    {
        if ($gitBranch === 'fail') {
            throw new SiteDeployFailedException(
                'Build failed: branch "fail" is a deterministic failure trigger.',
                $this->failLogs($gitBranch),
            );
        }

        $commitSha = substr(hash('sha256', $gitUrl.':'.$gitBranch), 0, 12);
        $logs = $this->successLogs($gitBranch, $commitSha);

        return [
            'commit_sha' => $commitSha,
            'url' => 'https://'.Str::slug(basename((string) parse_url($gitUrl, PHP_URL_PATH) ?: $providerSiteId)).'.omnex-sites.test',
            'logs' => $logs,
        ];
    }

    public function rollback(string $providerSiteId, string $commitSha): array
    {
        return ['url' => 'https://'.Str::slug($providerSiteId).'.omnex-sites.test'];
    }

    public function delete(string $providerSiteId): void
    {
        // Nothing to tear down in the in-memory sandbox.
    }

    private function successLogs(string $branch, string $commitSha): string
    {
        return implode("\n", [
            '[omnex-sites] cloning repository',
            "[omnex-sites] checkout {$branch}",
            '[omnex-sites] installing dependencies',
            '[omnex-sites] building static assets',
            "[omnex-sites] deploy succeeded @ {$commitSha}",
        ]);
    }

    private function failLogs(string $branch): string
    {
        return implode("\n", [
            '[omnex-sites] cloning repository',
            "[omnex-sites] checkout {$branch}",
            '[omnex-sites] installing dependencies',
            '[omnex-sites] building static assets',
            '[omnex-sites] ERROR: build script exited with code 1',
        ]);
    }
}
