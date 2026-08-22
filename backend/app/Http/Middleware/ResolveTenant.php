<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $context = app(TenantContext::class);

        if ($user !== null) {
            $membership = null;
            $requested = $request->header(config('omnex.tenant.header'));

            if ($requested !== null && $requested !== '') {
                $membership = $user->allMemberships()
                    ->where('organization_id', $requested)
                    ->where('status', 'active')
                    ->first();

                if ($membership === null) {
                    abort(403, 'You are not a member of this organization.');
                }
            } else {
                $membership = $user->allMemberships()->where('status', 'active')->first();
            }

            if ($membership !== null) {
                $context->set($membership->organization_id, $membership->organization);
                $this->setRlsContext($membership->organization_id, $user->getKey());
            }
        }

        $response = $next($request);

        if (config('omnex.enforce_rls')) {
            $this->clearRlsContext();
        }

        return $response;
    }

    private function setRlsContext(string $organizationId, string $userId): void
    {
        if (! config('omnex.enforce_rls')) {
            return;
        }

        // Session-scoped (is_local=false): with autocommit (no ambient
        // transaction) an is_local=true set_config is discarded as soon as the
        // statement commits, so the tenant GUC would never reach the queries
        // it is meant to filter. Cleared explicitly by clearRlsContext().
        DB::statement('SELECT set_config(?, ?, false)', ['nexus.tenant_id', $organizationId]);
        DB::statement('SELECT set_config(?, ?, false)', ['nexus.user_id', $userId]);
    }

    private function clearRlsContext(): void
    {
        DB::statement("SELECT set_config('nexus.tenant_id', '', false)");
        DB::statement("SELECT set_config('nexus.user_id', '', false)");
    }
}
