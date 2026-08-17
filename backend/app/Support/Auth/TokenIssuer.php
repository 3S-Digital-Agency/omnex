<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Issues a Sanctum token for the SPA and stamps it with the request context
 * (IP + user agent) so the session-management UI can recognize devices.
 */
final class TokenIssuer
{
    public static function issue(User $user, ?Request $request = null, ?string $tokenName = null): string
    {
        $request ??= request();

        $token = $user->createToken($tokenName ?? 'omnex-spa');
        $token->accessToken->forceFill([
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ])->save();

        return $token->plainTextToken;
    }
}
