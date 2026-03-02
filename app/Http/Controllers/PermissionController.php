<?php

namespace App\Http\Controllers;

use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    /**
     * GET /api/permissions
     *
     * List all permissions (grouped by group field).
     */
    public function index(): JsonResponse
    {
        $permissions = Permission::orderBy('group')->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data'    => PermissionResource::collection($permissions),
        ]);
    }
}
