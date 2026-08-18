<?php

namespace App\Http\Middleware;

use App\Support\Features\FeatureFlagService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route on a feature flag/perk for the active organization. Usage:
 *
 *   Route::get(...)->middleware('feature:cloud');
 *
 * When the flag is disabled the request is rejected with 403, so a disabled
 * module is enforced server-side — not merely hidden in the UI.
 */
class FeatureGate
{
    public function handle(Request $request, Closure $next, string $flag): Response
    {
        if (! app(FeatureFlagService::class)->enabled($flag)) {
            abort(403, 'This feature is not available on your current plan.');
        }

        return $next($request);
    }
}
