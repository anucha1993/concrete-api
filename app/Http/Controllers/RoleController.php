<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignPermissionRequest;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    /**
     * GET /api/roles
     */
    public function index(Request $request): JsonResponse
    {
        $roles = Role::with('permissions')->get();

        return response()->json([
            'success' => true,
            'data'    => RoleResource::collection($roles),
        ]);
    }

    /**
     * POST /api/roles
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = Role::create($request->validated());
        $role->load('permissions');

        return response()->json([
            'success' => true,
            'message' => 'สร้าง Role สำเร็จ',
            'data'    => new RoleResource($role),
        ], 201);
    }

    /**
     * GET /api/roles/{role}
     */
    public function show(Role $role): JsonResponse
    {
        $role->load('permissions');

        return response()->json([
            'success' => true,
            'data'    => new RoleResource($role),
        ]);
    }

    /**
     * PUT /api/roles/{role}
     */
    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $role->update($request->validated());
        $role->load('permissions');

        return response()->json([
            'success' => true,
            'message' => 'อัปเดต Role สำเร็จ',
            'data'    => new RoleResource($role),
        ]);
    }

    /**
     * DELETE /api/roles/{role}
     */
    public function destroy(Role $role): JsonResponse
    {
        // Prevent deleting role if users are assigned
        if ($role->users()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'ไม่สามารถลบ Role ที่มีผู้ใช้งานได้',
            ], 422);
        }

        $role->permissions()->detach();
        $role->delete();

        return response()->json([
            'success' => true,
            'message' => 'ลบ Role สำเร็จ',
        ]);
    }

    /**
     * POST /api/roles/{role}/permissions
     *
     * Assign (sync) permissions to a role.
     */
    public function assignPermissions(AssignPermissionRequest $request, Role $role): JsonResponse
    {
        $role->permissions()->sync($request->permissions);
        $role->load('permissions');

        return response()->json([
            'success' => true,
            'message' => 'กำหนดสิทธิ์สำเร็จ',
            'data'    => new RoleResource($role),
        ]);
    }
}
