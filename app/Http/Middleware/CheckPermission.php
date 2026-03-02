<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  string  ...$permissions  One or more permission names (OR logic)
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Load role with permissions if not already loaded
        if (!$user->relationLoaded('role')) {
            $user->load('role.permissions');
        }

        // Admin bypasses all permission checks
        if ($user->isAdmin()) {
            return $next($request);
        }

        // Check if user has any of the required permissions
        if (!$user->hasAnyPermission($permissions)) {
            return response()->json([
                'success' => false,
                'message' => 'คุณไม่มีสิทธิ์เข้าถึงทรัพยากรนี้ (Forbidden)',
            ], 403);
        }

        return $next($request);
    }
}
