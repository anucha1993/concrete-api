<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * GET /api/users
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::with('role');

        // Filter by role
        if ($request->filled('role_id')) {
            $query->where('role_id', $request->role_id);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Search by name or email
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = $request->input('per_page', 15);
        $users = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => UserResource::collection($users),
            'meta'    => [
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
                'per_page'     => $users->perPage(),
                'total'        => $users->total(),
            ],
        ]);
    }

    /**
     * POST /api/users
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['password'] = $data['password']; // Will be auto-hashed via model cast

        $user = User::create($data);
        $user->load('role');

        return response()->json([
            'success' => true,
            'message' => 'สร้างผู้ใช้สำเร็จ',
            'data'    => new UserResource($user),
        ], 201);
    }

    /**
     * GET /api/users/{user}
     */
    public function show(User $user): JsonResponse
    {
        $user->load('role.permissions');

        return response()->json([
            'success' => true,
            'data'    => new UserResource($user),
        ]);
    }

    /**
     * PUT /api/users/{user}
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        $user->update($data);
        $user->load('role');

        return response()->json([
            'success' => true,
            'message' => 'อัปเดตผู้ใช้สำเร็จ',
            'data'    => new UserResource($user),
        ]);
    }

    /**
     * DELETE /api/users/{user}
     */
    public function destroy(User $user): JsonResponse
    {
        // Prevent deleting self
        if (auth()->id() === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'ไม่สามารถลบบัญชีตัวเองได้',
            ], 422);
        }

        // Revoke all tokens
        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'ลบผู้ใช้สำเร็จ',
        ]);
    }
}
