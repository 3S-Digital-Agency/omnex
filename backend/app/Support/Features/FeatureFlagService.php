<?php

namespace App\Support\Features;

use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Central feature-flag / perk engine.
 *
 * Every flag is defined in `config('omnex.features')` with a type, a platform
 * default and per-tier values. For the active organization the effective value
 * is, in priority order:
 *
 *   1. an explicit override in `organizations.settings.features.<flag>`,
 *   2. the plan-tier default (`tiers[<plan_tier>]`),
 *   3. the platform `default`.
 *
 * Nothing is simulated: a boolean flag of `false` is enforced by the
 * `FeatureGate` middleware (routes) and can be read by the frontend to hide
 * navigation/controls; numeric flags carry plan limits (0 = unlimited).
 */
final class FeatureFlagService
{
    /**
     * @return array<int, array{key: string, label: string, type: string, value: mixed, enabled: bool, source: 'override'|'plan'|'default'}>
     */
    public function all(): array
    {
        $organization = app(TenantContext::class)->organization();
        $tier = $organization?->plan_tier ?? 'free';
        $overrides = $organization?->settings['features'] ?? [];

        return collect(config('omnex.features', []))
            ->map(function (array $definition, string $key) use ($tier, $overrides) {
                [$value, $source] = $this->resolve($definition, $key, $tier, $overrides);

                return [
                    'key' => $key,
                    'label' => $definition['label'] ?? $key,
                    'type' => $definition['type'] ?? 'boolean',
                    'value' => $value,
                    'enabled' => (bool) $value,
                    'source' => $source,
                ];
            })
            ->values()
            ->all();
    }

    public function enabled(string $key): bool
    {
        $flag = $this->find($key);

        return (bool) $flag['value'];
    }

    public function value(string $key, mixed $default = null): mixed
    {
        return $this->find($key)['value'] ?? $default;
    }

    /**
     * Set (or update) an organization-level override for a flag. The value is
     * coerced to the flag's declared type before persisting.
     *
     * @return array{key: string, label: string, type: string, value: mixed, enabled: bool, source: 'override'}
     */
    public function setOverride(string $key, mixed $value): array
    {
        $organization = app(TenantContext::class)->organization();

        if ($organization === null) {
            throw ValidationException::withMessages(['feature' => ['No active organization.']]);
        }

        $definition = config("omnex.features.{$key}");

        if ($definition === null) {
            throw ValidationException::withMessages(['feature' => ["Unknown feature flag [{$key}]."]]);
        }

        $value = $this->coerce($definition['type'] ?? 'boolean', $value);

        $settings = $organization->settings ?? [];
        $settings['features'] = array_merge($settings['features'] ?? [], [$key => $value]);
        $organization->update(['settings' => $settings]);

        return [
            'key' => $key,
            'label' => $definition['label'] ?? $key,
            'type' => $definition['type'] ?? 'boolean',
            'value' => $value,
            'enabled' => (bool) $value,
            'source' => 'override',
        ];
    }

    /**
     * Remove an organization override so the flag falls back to its tier.
     *
     * @return array{key: string, label: string, type: string, value: mixed, enabled: bool, source: 'plan'|'default'}
     */
    public function resetOverride(string $key): array
    {
        $organization = app(TenantContext::class)->organization();

        if ($organization === null) {
            throw ValidationException::withMessages(['feature' => ['No active organization.']]);
        }

        $settings = $organization->settings ?? [];

        if (isset($settings['features'])) {
            unset($settings['features'][$key]);
            if ($settings['features'] === []) {
                unset($settings['features']);
            }
            $organization->update(['settings' => $settings]);
        }

        return $this->find($key);
    }

    /**
     * @return array{key: string, label: string, type: string, value: mixed, enabled: bool, source: 'override'|'plan'|'default'}
     */
    private function find(string $key): array
    {
        $flag = collect($this->all())->firstWhere('key', $key);

        if ($flag === null) {
            throw ValidationException::withMessages(['feature' => ["Unknown feature flag [{$key}]."]]);
        }

        return $flag;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $overrides
     * @return array{0: mixed, 1: 'override'|'plan'|'default'}
     */
    private function resolve(array $definition, string $key, string $tier, array $overrides): array
    {
        if (array_key_exists($key, $overrides)) {
            return [$this->coerce($definition['type'] ?? 'boolean', $overrides[$key]), 'override'];
        }

        if (array_key_exists($tier, $definition['tiers'] ?? [])) {
            return [$this->coerce($definition['type'] ?? 'boolean', $definition['tiers'][$tier]), 'plan'];
        }

        return [$this->coerce($definition['type'] ?? 'boolean', $definition['default'] ?? false), 'default'];
    }

    private function coerce(string $type, mixed $value): mixed
    {
        return match ($type) {
            'number' => (int) $value,
            default => (bool) $value,
        };
    }
}
