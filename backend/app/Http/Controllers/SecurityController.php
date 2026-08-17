<?php

namespace App\Http\Controllers;

use App\Http\Resources\SecurityFindingResource;
use App\Models\Organization;
use App\Models\SecurityFinding;
use App\Models\SslCheck;
use App\Support\Security\SecurityService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SecurityController extends Controller
{
    public function __construct(private SecurityService $security) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('security.read');

        return response()->json($this->report($this->security->scan()));
    }

    public function scan(Request $request): JsonResponse
    {
        $this->authorize('security.manage');

        return response()->json($this->report($this->security->scan(audit: true)));
    }

    public function dismiss(Request $request, string $finding): JsonResponse
    {
        $this->authorize('security.manage');

        $finding = SecurityFinding::findOrFail($finding);

        return response()->json(new SecurityFindingResource($this->security->dismiss($finding)));
    }

    public function reopen(Request $request, string $finding): JsonResponse
    {
        $this->authorize('security.manage');

        $finding = SecurityFinding::findOrFail($finding);

        return response()->json(new SecurityFindingResource($this->security->reopen($finding)));
    }

    /** MFA enforcement policy for the active organization. */
    public function settings(Request $request): JsonResponse
    {
        $this->authorize('security.read');

        $organization = $this->organization();

        return response()->json(['mfa_policy' => $organization->mfa_policy]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorize('security.manage');

        $request->validate([
            'mfa_policy' => ['required', Rule::in(['optional', 'required'])],
        ]);

        $organization = $this->organization();
        $organization->update(['mfa_policy' => $request->input('mfa_policy')]);

        return response()->json(['mfa_policy' => $organization->mfa_policy]);
    }

    /** Persisted score samples (newest last) for the score timeline. */
    public function history(Request $request): JsonResponse
    {
        $this->authorize('security.read');

        $samples = $this->security->history((int) $request->integer('limit', 30));

        return response()->json([
            'samples' => array_map(fn ($sample) => [
                'score' => $sample->score,
                'open' => $sample->open,
                'high' => $sample->high,
                'medium' => $sample->medium,
                'low' => $sample->low,
                'created_at' => $sample->created_at?->toISOString(),
            ], $samples),
        ]);
    }

    /** Latest certificate-monitoring checks for the active organization. */
    public function sslChecks(Request $request): JsonResponse
    {
        $this->authorize('security.read');

        $checks = SslCheck::query()
            ->orderByDesc('checked_at')
            ->limit((int) $request->integer('per_page', 50))
            ->get()
            ->map(fn (SslCheck $check) => [
                'id' => $check->id,
                'target_type' => $check->target_type,
                'target_id' => $check->target_id,
                'status' => $check->status,
                'days_remaining' => $check->days_remaining,
                'details' => $check->details,
                'checked_at' => $check->checked_at?->toISOString(),
            ])
            ->values();

        return response()->json($checks);
    }

    private function organization(): Organization
    {
        return Organization::findOrFail(app(TenantContext::class)->id());
    }

    /**
     * @param  array{score: int, summary: array<string, int>, findings: array<int, SecurityFinding>}  $report
     */
    private function report(array $report): array
    {
        return [
            'score' => $report['score'],
            'summary' => $report['summary'],
            'findings' => SecurityFindingResource::collection($report['findings']),
        ];
    }
}
