<?php

namespace App\Contracts;

use App\Support\Sites\SiteDeployFailedException;

/**
 * Port for static/hosting platforms (Vercel, Netlify, Cloudflare Pages,
 * Forge, a self-hosted gateway…). OMNEX owns the site + deployment model and
 * lifecycle; a provider only provisions sites and runs build/deploy/rollback
 * operations against its platform.
 *
 * Implementations must be side-effect free w.r.t. the OMNEX database — the
 * SiteService is the only writer to the `sites`/`site_deployments` tables.
 */
interface SiteProviderInterface
{
    public function name(): string;

    public function label(): string;

    /**
     * Whether the provider has the credentials required to reach a real
     * platform. The sandbox is always configured; real providers activate
     * only once their credentials are set.
     */
    public function isConfigured(): bool;

    /**
     * Provision a site and return its remote identity + canonical URL.
     *
     * @return array{provider_site_id: string, url: string}
     */
    public function provision(string $name, string $framework, string $gitUrl, string $gitBranch): array;

    /**
     * Build + deploy the given ref and return the resulting revision.
     *
     * @param  array<string, string>  $environment  decrypted env vars (server-side only)
     * @return array{commit_sha: string, url: string, logs: string}
     *
     * @throws SiteDeployFailedException
     */
    public function deploy(string $providerSiteId, string $gitUrl, string $gitBranch, array $environment): array;

    /**
     * @return array{url: string}
     */
    public function rollback(string $providerSiteId, string $commitSha): array;

    public function delete(string $providerSiteId): void;
}
