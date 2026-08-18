<?php

namespace App\Http\Controllers;

use App\Support\Features\FeatureFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeatureFlagController extends Controller
{
    public function __construct(private FeatureFlagService $features) {}

    /**
     * Effective flags for the active organization. Readable by any tenant
     * member — the frontend needs it to render navigation and controls.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->features->all()]);
    }

    public function update(Request $request, string $flag): JsonResponse
    {
        $this->authorize('organizations.manage');

        $data = $request->validate(['value' => ['required']]);

        return response()->json(['data' => $this->features->setOverride($flag, $data['value'])]);
    }

    public function reset(Request $request, string $flag): JsonResponse
    {
        $this->authorize('organizations.manage');

        return response()->json(['data' => $this->features->resetOverride($flag)]);
    }
}
