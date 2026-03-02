<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckActiveUser
{
    /**
     * Ensure the authenticated user has ACTIVE status.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->isActive()) {
            // Revoke all tokens for inactive user
            $user->tokens()->delete();

            return response()->json([
                'success' => false,
                'message' => 'บัญชีผู้ใช้ถูกระงับ กรุณาติดต่อผู้ดูแลระบบ (Account inactive)',
            ], 403);
        }

        return $next($request);
    }
}
