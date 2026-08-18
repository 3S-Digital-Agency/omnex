<?php

namespace App\Support\Providers;

use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Uniform "which provider should this service use?" resolution.
 *
 * Each service (storage, sites, cloud, domains, DNS, SSL…) already owns a
 * Strategy/Factory registry (`…ProviderRegistry`). This trait adds the one
 * piece they were missing: a per-organization override that falls back to the
 * environment default. The active provider is resolved from
 * `organizations.settings[<settings key>]`, then from the `<config key>` env
 * value, so switching a provider never touches code — it is either an org
 * setting (self-service) or an environment variable (platform default).
 *
 * Services using this trait must expose a `$providers` registry property with
 * `get()`, `names()` and (for `activeProvider()`) `label()`/`isConfigured()`.
 */
trait ResolvesTenantProvider
{
    /** Config key holding the environment-level default (e.g. "omnex.sites.provider"). */
    abstract protected function providerConfigKey(): string;

    /** Organization `settings` key for the per-tenant override (e.g. "site_provider"). */
    abstract protected function providerSettingsKey(): string;

    /**
     * The active provider name: per-organization override, else the env default.
     */
    public function activeProviderName(): string
    {
        $organization = app(TenantContext::class)->organization();
        $override = $organization?->settings[$this->providerSettingsKey()] ?? null;

        return (string) ($override ?: config($this->providerConfigKey(), 'sandbox'));
    }

    /**
     * @return array{name: string, label: string, configured: bool, active: bool}
     */
    public function activeProvider(): array
    {
        $name = $this->activeProviderName();
        $provider = $this->providers->get($name);

        return [
            'name' => $provider->name(),
            'label' => $provider->label(),
            'configured' => $provider->isConfigured(),
            'active' => true,
        ];
    }

    /**
     * Persist the active provider on the current organization. Rejects unknown
     * names before anything is written; the value survives across requests via
     * the organization `settings` JSON (the tenant isolation boundary).
     *
     * @return array{name: string, label: string, configured: bool, active: bool}
     */
    public function setProvider(string $name): array
    {
        if (! in_array($name, $this->providers->names(), true)) {
            throw ValidationException::withMessages(['name' => ["Unknown provider [{$name}]."]]);
        }

        $organization = app(TenantContext::class)->organization();

        if ($organization !== null) {
            $settings = $organization->settings ?? [];
            $settings[$this->providerSettingsKey()] = $name;
            $organization->update(['settings' => $settings]);
        }

        return $this->activeProvider();
    }
}
