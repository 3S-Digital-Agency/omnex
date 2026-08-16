<?php

namespace App\Support\Sites;

use App\Contracts\SiteProviderInterface;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Support\Audit\AuditLogger;
use Illuminate\Validation\ValidationException;

/**
 * Owns the OMNEX Sites lifecycle: provision, deploy, rollback, env vars and
 * deletion. OMNEX is the system of record for sites and deployments; a
 * SiteProviderInterface only performs platform operations. Environment
 * variables are encrypted at rest and never returned by the API (only their
 * keys are exposed). Every mutation is audited.
 */
final class SiteService
{
    public function __construct(private SiteProviderRegistry $providers) {}

    /**
     * @return array<int, array{name: string, label: string, configured: bool}>
     */
    public function providers(): array
    {
        return $this->providers->all();
    }

    private function provider(?string $name = null): SiteProviderInterface
    {
        $provider = $this->providers->get($name ?? $this->providers->get()->name());

        if (! $provider->isConfigured()) {
            throw new SiteProviderException("The [{$provider->label()}] sites provider is not configured.");
        }

        return $provider;
    }

    /**
     * @param  array<string, string>  $environment
     */
    public function create(
        string $name,
        string $framework,
        string $gitUrl,
        string $gitBranch,
        array $environment = [],
        ?string $providerName = null,
    ): Site {
        $name = $this->validateName($name);
        $framework = $this->validateFramework($framework);
        $gitUrl = $this->validateGitUrl($gitUrl);
        $gitBranch = $this->validateBranch($gitBranch);
        $environment = $this->validateEnvironment($environment);

        $provider = $this->provider($providerName);

        $result = $provider->provision($name, $framework, $gitUrl, $gitBranch);

        $site = Site::create([
            'name' => $name,
            'framework' => $framework,
            'git_url' => $gitUrl,
            'git_branch' => $gitBranch,
            'provider' => $provider->name(),
            'provider_site_id' => $result['provider_site_id'],
            'status' => 'provisioning',
            'url' => $result['url'],
            'environment_variables' => $environment,
        ]);

        AuditLogger::record('site.created', 'site', $site->id, null, [
            'name' => $site->name,
            'framework' => $site->framework,
            'provider' => $site->provider,
        ]);

        return $site;
    }

    public function update(Site $site, array $input): Site
    {
        $before = $this->snapshot($site);
        $updates = [];

        if (array_key_exists('name', $input)) {
            $updates['name'] = $this->validateName($input['name']);
        }

        if (array_key_exists('framework', $input)) {
            $updates['framework'] = $this->validateFramework($input['framework']);
        }

        if (array_key_exists('git_url', $input)) {
            $updates['git_url'] = $this->validateGitUrl($input['git_url']);
        }

        if (array_key_exists('git_branch', $input)) {
            $updates['git_branch'] = $this->validateBranch($input['git_branch']);
        }

        if (array_key_exists('environment_variables', $input)) {
            $updates['environment_variables'] = $this->validateEnvironment($input['environment_variables']);
        }

        if ($updates !== []) {
            $site->update($updates);
        }

        AuditLogger::record('site.updated', 'site', $site->id, $before, $this->snapshot($site));

        return $site->fresh();
    }

    public function deploy(Site $site): SiteDeployment
    {
        $this->provider($site->provider);

        $previous = $site->current_deployment_id !== null ? $site->currentDeployment : null;

        $number = (int) $site->deployments()->max('number') + 1;

        $deployment = SiteDeployment::create([
            'site_id' => $site->id,
            'number' => $number,
            'status' => 'building',
        ]);

        try {
            $result = $this->provider($site->provider)->deploy(
                $site->provider_site_id ?? '',
                $site->git_url,
                $site->git_branch,
                $site->environment(),
            );

            $deployment->update([
                'status' => 'live',
                'commit_sha' => $result['commit_sha'],
                'url' => $result['url'],
                'logs' => $result['logs'],
                'deployed_at' => now(),
            ]);

            $site->update([
                'current_deployment_id' => $deployment->id,
                'url' => $result['url'],
                'status' => 'ready',
            ]);

            AuditLogger::record('site.deployed', 'site', $site->id, null, [
                'deployment' => $number,
                'commit_sha' => $result['commit_sha'],
            ]);

            return $deployment->fresh();
        } catch (SiteDeployFailedException $e) {
            $deployment->update([
                'status' => 'failed',
                'logs' => $e->getLogs() !== '' ? $e->getLogs() : $e->getMessage(),
            ]);

            // Automatic rollback: keep serving the previous live deployment.
            if ($previous !== null) {
                AuditLogger::record('site.deploy_failed_rolled_back', 'site', $site->id, [
                    'failed_deployment' => $number,
                ], [
                    'rolled_back_to' => $previous->id,
                ]);
            } else {
                $site->update(['status' => 'failed']);
                AuditLogger::record('site.deploy_failed', 'site', $site->id, null, ['deployment' => $number]);
            }

            return $deployment->fresh();
        }
    }

    public function rollback(Site $site, SiteDeployment $target): SiteDeployment
    {
        if ($target->status !== 'live') {
            throw ValidationException::withMessages(['deployment' => ['Only a live deployment can be rolled back to.']]);
        }

        if ($site->current_deployment_id === $target->id) {
            throw ValidationException::withMessages(['deployment' => ['This deployment is already serving traffic.']]);
        }

        $current = $site->currentDeployment;

        $this->provider($site->provider)->rollback($site->provider_site_id ?? '', $target->commit_sha ?? '');

        $site->update(['current_deployment_id' => $target->id]);

        if ($current !== null) {
            $current->update(['status' => 'rolled_back']);
        }

        AuditLogger::record('site.rolled_back', 'site', $site->id, [
            'from' => $current?->id,
        ], [
            'to' => $target->id,
        ]);

        return $target->fresh();
    }

    public function delete(Site $site): void
    {
        $before = $this->snapshot($site);

        $this->provider($site->provider)->delete($site->provider_site_id ?? '');

        $site->deployments()->delete();
        $site->delete();

        AuditLogger::record('site.deleted', 'site', null, $before, null);
    }

    /**
     * @return array<int, SiteDeployment>
     */
    public function deployments(Site $site): array
    {
        return $site->deployments()->get()->all();
    }

    private function validateName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['The name is required.']]);
        }

        if (mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => ['The name must not exceed 255 characters.']]);
        }

        return $name;
    }

    private function validateFramework(string $framework): string
    {
        $framework = strtolower(trim($framework));

        if (! in_array($framework, config('omnex.sites.frameworks', ['static', 'laravel', 'next']), true)) {
            throw ValidationException::withMessages(['framework' => ['The framework is not supported.']]);
        }

        return $framework;
    }

    private function validateGitUrl(string $gitUrl): string
    {
        $gitUrl = trim($gitUrl);

        if ($gitUrl === '') {
            throw ValidationException::withMessages(['git_url' => ['The git URL is required.']]);
        }

        if (filter_var($gitUrl, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::withMessages(['git_url' => ['The git URL must be a valid URL.']]);
        }

        return $gitUrl;
    }

    private function validateBranch(string $branch): string
    {
        $branch = trim($branch);

        if ($branch === '') {
            throw ValidationException::withMessages(['git_branch' => ['The git branch is required.']]);
        }

        return $branch;
    }

    /**
     * @param  array<string, string>  $environment
     * @return array<string, string>
     */
    private function validateEnvironment(array $environment): array
    {
        $clean = [];

        foreach ($environment as $key => $value) {
            $key = trim((string) $key);

            if ($key === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                throw ValidationException::withMessages(['environment_variables' => ["The environment key [{$key}] is invalid."]]);
            }

            $clean[$key] = (string) $value;
        }

        return $clean;
    }

    /**
     * @return array{name: string, framework: string, git_url: string, git_branch: string, status: string, url: ?string}
     */
    private function snapshot(Site $site): array
    {
        return [
            'name' => $site->name,
            'framework' => $site->framework,
            'git_url' => $site->git_url,
            'git_branch' => $site->git_branch,
            'status' => $site->status,
            'url' => $site->url,
        ];
    }
}
