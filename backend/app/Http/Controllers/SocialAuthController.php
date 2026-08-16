<?php

namespace App\Http\Controllers;

use App\Http\Resources\InvitationResource;
use App\Http\Resources\MembershipResource;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\SocialAccountResource;
use App\Http\Resources\UserResource;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\SocialAuth\SocialAuthException;
use App\Support\SocialAuth\SocialAuthRegistry;
use App\Support\SocialAuth\SocialAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SocialAuthController extends Controller
{
    public function __construct(
        private SocialAuthRegistry $registry,
        private SocialAuthService $social,
    ) {}

    public function providers(): JsonResponse
    {
        return response()->json(['data' => $this->registry->all()]);
    }

    public function redirect(Request $request, string $providerName): JsonResponse
    {
        try {
            $provider = $this->social->provider($providerName);
        } catch (SocialAuthException|\InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        $state = Str::random(40);
        $intent = 'login';
        $userId = null;

        if ($request->boolean('link') && $request->user() !== null) {
            $intent = 'link';
            $userId = $request->user()->id;
        }

        Cache::put($this->stateKey($state), [
            'provider' => $providerName,
            'intent' => $intent,
            'user_id' => $userId,
        ], now()->addMinutes(5));

        return response()->json(['url' => $provider->redirectUrl($state)]);
    }

    public function callback(Request $request, string $providerName): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        $meta = Cache::pull($this->stateKey($state));

        if ($meta === null || ($meta['provider'] ?? null) !== $providerName) {
            abort(422, 'Invalid or expired OAuth state.');
        }

        $actor = ($meta['intent'] ?? null) === 'link' && ! empty($meta['user_id'])
            ? User::find($meta['user_id'])
            : null;

        try {
            $user = $this->social->resolve($providerName, $code, $actor);
        } catch (SocialAuthException|\InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        if ($actor !== null) {
            AuditLogger::record('user.social_linked', 'user', $user->id, null, ['provider' => $providerName]);
        }

        $complete = Str::random(64);
        Cache::put($this->completeKey($complete), $user->id, now()->addSeconds(config('socialauth.completion_ttl', 300)));

        return redirect()->away(
            config('socialauth.frontend_url').'/social/callback?code='.$complete.'&provider='.$providerName,
        );
    }

    public function complete(Request $request): JsonResponse
    {
        $code = (string) $request->input('code', '');
        $userId = Cache::pull($this->completeKey($code));

        if ($userId === null) {
            abort(401, 'The social login code has expired or is invalid.');
        }

        return $this->sessionResponse(User::findOrFail($userId));
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => SocialAccountResource::collection($request->user()->socialAccounts()->get()),
        ]);
    }

    public function destroy(Request $request, string $providerName): JsonResponse
    {
        $this->social->unlink($request->user(), $providerName);

        AuditLogger::record('user.social_unlinked', 'user', $request->user()->id, null, ['provider' => $providerName]);

        return response()->json(['message' => 'Provider unlinked.']);
    }

    private function sessionResponse(User $user): JsonResponse
    {
        $user->last_login_at = now();
        $user->save();

        AuditLogger::record('user.logged_in', 'user', $user->id);

        $memberships = $user->allMemberships()->with(['role', 'organization'])->get();
        $active = $user->allMemberships()
            ->where('status', 'active')
            ->with('organization')
            ->first();

        return response()->json([
            'token' => $user->createToken('omnex-spa')->plainTextToken,
            'user' => new UserResource($user),
            'memberships' => MembershipResource::collection($memberships),
            'active_organization' => $active?->organization
                ? new OrganizationResource($active->organization)
                : null,
            'permissions' => $user->permissionKeys(),
            'pending_invitations' => InvitationResource::collection(
                Invitation::withoutTenancy()
                    ->where('email', $user->email)
                    ->where('status', 'pending')
                    ->with(['organization', 'role'])
                    ->get(),
            ),
        ]);
    }

    private function stateKey(string $state): string
    {
        return "social:state:{$state}";
    }

    private function completeKey(string $code): string
    {
        return "social:complete:{$code}";
    }
}
