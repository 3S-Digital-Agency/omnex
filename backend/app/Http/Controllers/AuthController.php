<?php

namespace App\Http\Controllers;

use App\Http\Resources\InvitationResource;
use App\Http\Resources\MembershipResource;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Security\Totp;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'password' => $data['password'],
            'email_verified_at' => now(),
            'status' => 'active',
        ]);

        AuditLogger::record('user.registered', 'user', $user->id, null, ['email' => $user->email]);

        return response()->json([
            'token' => $this->issueToken($user),
            'user' => new UserResource($user),
            'memberships' => [],
            'active_organization' => null,
            'permissions' => [],
            'pending_invitations' => [],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', strtolower($data['email']))->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['This account is not active.'],
            ]);
        }

        if ($user->mfa_enabled) {
            $challenge = Str::random(64);
            Cache::put($this->mfaChallengeKey($challenge), $user->id, config('nexus.mfa.challenge_ttl'));

            return response()->json([
                'mfa_required' => true,
                'mfa_token' => $challenge,
            ]);
        }

        return $this->loginResponse($user);
    }

    public function verifyMfa(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mfa_token' => ['required', 'string'],
            'code' => ['required_without:recovery_code', 'string'],
            'recovery_code' => ['required_without:code', 'string'],
        ]);

        $userId = Cache::pull($this->mfaChallengeKey($data['mfa_token']));

        if ($userId === null) {
            abort(401, 'The MFA challenge has expired or is invalid.');
        }

        $user = User::findOrFail($userId);

        if (isset($data['recovery_code'])) {
            $valid = $this->consumeRecoveryCode($user, $data['recovery_code']);
        } else {
            $valid = Totp::verify($user->mfa_secret, $data['code'], config('nexus.mfa.verification_window'));
        }

        if (! $valid) {
            AuditLogger::record('user.mfa_failed', 'user', $user->id, null, null, 'failure');

            throw ValidationException::withMessages([
                'code' => ['Invalid verification code.'],
            ]);
        }

        return $this->loginResponse($user);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $memberships = $user->allMemberships()->with(['role', 'organization'])->get();
        $pendingInvitations = Invitation::withoutTenancy()
            ->where('email', $user->email)
            ->where('status', 'pending')
            ->with(['organization', 'role'])
            ->get();

        return response()->json([
            'user' => new UserResource($user),
            'memberships' => MembershipResource::collection($memberships),
            'active_organization' => app(TenantContext::class)->organization()
                ? new OrganizationResource(app(TenantContext::class)->organization())
                : null,
            'permissions' => $user->permissionKeys(),
            'pending_invitations' => InvitationResource::collection($pendingInvitations),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(config('nexus.locales', ['en', 'fr']))],
        ]);

        $user = $request->user();
        $previous = $user->locale;
        $user->locale = $data['locale'];
        $user->save();

        AuditLogger::record('user.profile_updated', 'user', $user->id, [
            'locale' => $previous,
        ], [
            'locale' => $user->locale,
        ]);

        return response()->json(new UserResource($user));
    }

    public function logout(Request $request): JsonResponse
    {
        AuditLogger::record('user.logged_out', 'user', $request->user()->id);
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function setupMfa(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->mfa_enabled) {
            abort(409, 'MFA is already enabled.');
        }

        $secret = Totp::generateSecret();
        $user->mfa_secret = $secret;
        $user->save();

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => Totp::otpauthUri($secret, $user->email, config('nexus.mfa.issuer')),
        ]);
    }

    public function confirmMfa(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);

        $user = $request->user();

        if ($user->mfa_secret === null || $user->mfa_enabled) {
            abort(409, 'MFA has not been set up or is already enabled.');
        }

        if (! Totp::verify($user->mfa_secret, $data['code'], config('nexus.mfa.verification_window'))) {
            throw ValidationException::withMessages(['code' => ['Invalid verification code.']]);
        }

        $recoveryCodes = $this->generateRecoveryCodes();
        $user->mfa_enabled = true;
        $user->recovery_codes = array_map(fn ($code) => hash('sha256', $code), $recoveryCodes);
        $user->save();

        AuditLogger::record('user.mfa_enabled', 'user', $user->id);

        return response()->json(['recovery_codes' => $recoveryCodes]);
    }

    public function disableMfa(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);

        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => ['The password is incorrect.']]);
        }

        $user->mfa_enabled = false;
        $user->mfa_secret = null;
        $user->recovery_codes = null;
        $user->save();

        AuditLogger::record('user.mfa_disabled', 'user', $user->id);

        return response()->json(['message' => 'MFA disabled.']);
    }

    private function loginResponse(User $user): JsonResponse
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
            'token' => $this->issueToken($user),
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
                    ->get()
            ),
        ]);
    }

    private function issueToken(User $user): string
    {
        return $user->createToken('nexus-spa')->plainTextToken;
    }

    private function mfaChallengeKey(string $token): string
    {
        return "mfa:challenge:{$token}";
    }

    private function generateRecoveryCodes(?int $count = null): array
    {
        $count ??= config('nexus.mfa.recovery_codes_count');

        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(Str::random(10));
        }

        return $codes;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->recovery_codes ?? [];
        $hashed = hash('sha256', strtoupper(trim($code)));
        $index = array_search($hashed, $codes, true);

        if ($index === false) {
            return false;
        }

        unset($codes[$index]);
        $user->recovery_codes = array_values($codes);
        $user->save();

        return true;
    }
}
