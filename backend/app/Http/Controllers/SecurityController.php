<?php

namespace App\Http\Controllers;

use App\Http\Resources\SecurityFindingResource;
use App\Models\SecurityFinding;
use App\Support\Security\SecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
