<?php

namespace App\Http\Controllers;

use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::whereNull('organization_id')
            ->where('is_system', true)
            ->with('permissions')
            ->get();

        return response()->json(['data' => RoleResource::collection($roles)]);
    }
}
