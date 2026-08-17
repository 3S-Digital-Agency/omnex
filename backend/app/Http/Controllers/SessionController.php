<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Session management for the current user: list active API sessions with
 * device context (IP + user agent), revoke a specific session or all other
 * sessions. A user can only ever manage their own tokens — the queries are
 * scoped to the authenticated user.
 */
class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()?->id;

        $sessions = $request->user()
            ->tokens()
            ->latest('last_used_at')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'ip_address' => $token->ip_address,
                'user_agent' => $token->user_agent,
                'last_used_at' => $token->last_used_at?->toISOString(),
                'created_at' => $token->created_at?->toISOString(),
                'is_current' => $token->id === $currentId,
            ])
            ->values();

        return response()->json($sessions);
    }

    public function destroy(Request $request, string $session): JsonResponse
    {
        $token = $request->user()->tokens()->findOrFail($session);
        $token->delete();

        return response()->json(['revoked' => true]);
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()?->id;

        $request->user()->tokens()
            ->when($currentId !== null, fn ($query) => $query->where('id', '!=', $currentId))
            ->delete();

        return response()->json(['revoked' => true]);
    }
}
