<?php

namespace App\Providers;

use App\Events\DnsRecordChanged;
use App\Events\DnssecChanged;
use App\Events\DomainExpiring;
use App\Events\DomainRegistered;
use App\Events\DomainRenewed;
use App\Events\DomainTransferred;
use App\Events\DomainUpdated;
use App\Listeners\DomainEventAuditor;
use App\Contracts\DnsPropagationCheckerInterface;
use App\Listeners\NotifyExpiringDomain;
use App\Models\User;
use App\Support\Domains\DnsPropagationService;
use App\Support\Domains\DnsProviderRegistry;
use App\Support\Domains\DnsService;
use App\Support\Domains\Providers\SandboxDnsPropagationChecker;
use App\Support\Domains\DomainProviderRegistry;
use App\Support\Domains\DomainService;
use App\Support\SocialAuth\SocialAuthRegistry;
use App\Support\SocialAuth\SocialAuthService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, fn () => new TenantContext);
        $this->app->singleton(DomainProviderRegistry::class, fn () => new DomainProviderRegistry);
        $this->app->singleton(DnsProviderRegistry::class, fn () => new DnsProviderRegistry);
        $this->app->bind(DnsPropagationCheckerInterface::class, fn () => new SandboxDnsPropagationChecker);
        $this->app->singleton(DnsPropagationService::class);
        $this->app->singleton(DomainService::class);
        $this->app->singleton(DnsService::class);
        $this->app->singleton(SocialAuthRegistry::class, fn () => new SocialAuthRegistry);
        $this->app->singleton(SocialAuthService::class);
    }

    public function boot(): void
    {
        // Default-deny authorization. A permission key (e.g. "organizations.manage")
        // is checked first via the user's active-organization role. If it is not
        // granted, the gate falls through to model policies. If neither grants
        // access, the request is denied — nothing is allowed implicitly.
        Gate::before(function (?User $user, string $ability) {
            if ($user !== null && $user->hasPermission($ability)) {
                return true;
            }

            return null;
        });

        // Domain/DNS event bus: services publish, listeners side-effect
        // (audit stream, expiration notifications). Keep handlers synchronous
        // so the tenant/user context is intact when they run.
        Event::listen(DomainRegistered::class, DomainEventAuditor::class);
        Event::listen(DomainRenewed::class, DomainEventAuditor::class);
        Event::listen(DomainTransferred::class, DomainEventAuditor::class);
        Event::listen(DomainUpdated::class, DomainEventAuditor::class);
        Event::listen(DomainExpiring::class, DomainEventAuditor::class);
        Event::listen(DomainExpiring::class, NotifyExpiringDomain::class);
        Event::listen(DnsRecordChanged::class, DomainEventAuditor::class);
        Event::listen(DnssecChanged::class, DomainEventAuditor::class);
    }
}
