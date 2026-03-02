<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\PdaToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PdaController extends Controller
{
    /* ══════════════════════════════════════════════════════════════
       Admin endpoints (auth:sanctum required)
       ══════════════════════════════════════════════════════════════ */

    /**
     * Generate a new PDA token (8 hours expiry).
     */
    public function createToken(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'nullable|string|max:100',
        ]);

        $token = PdaToken::create([
            'token'      => Str::random(48),
            'name'       => $request->name ?: 'PDA ' . now()->format('d/m/Y H:i'),
            'created_by' => $request->user()->id,
            'expires_at' => now()->addHours(8),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'สร้าง Token สำเร็จ — หมดอายุใน 8 ชั่วโมง',
            'data'    => [
                'id'         => $token->id,
                'token'      => $token->token,
                'name'       => $token->name,
                'expires_at' => $token->expires_at->toISOString(),
                'created_by' => $request->user()->name,
            ],
        ]);
    }

    /**
     * List all PDA tokens (newest first).
     */
    public function listTokens(): JsonResponse
    {
        $tokens = PdaToken::with('creator:id,name')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (PdaToken $t) => [
                'id'          => $t->id,
                'name'        => $t->name,
                'token'       => $t->token,
                'expires_at'  => $t->expires_at->toISOString(),
                'is_expired'  => $t->expires_at->isPast(),
                'is_revoked'  => $t->is_revoked,
                'is_valid'    => $t->isValid(),
                'scan_count'  => $t->scan_count,
                'last_used_at' => $t->last_used_at?->toISOString(),
                'created_by'  => $t->creator?->name ?? '-',
                'created_at'  => $t->created_at->toISOString(),
            ]);

        return response()->json([
            'success' => true,
            'data'    => $tokens,
        ]);
    }

    /**
     * Revoke a PDA token.
     */
    public function revokeToken(PdaToken $pdaToken): JsonResponse
    {
        $pdaToken->update(['is_revoked' => true]);

        return response()->json([
            'success' => true,
            'message' => 'เพิกถอน Token แล้ว',
        ]);
    }

    /* ══════════════════════════════════════════════════════════════
       Public endpoints (token-based, no login required)
       ══════════════════════════════════════════════════════════════ */

    /**
     * Validate PDA token and return basic info.
     */
    public function validateToken(Request $request): JsonResponse
    {
        $token = $this->resolveToken($request);

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Token ไม่ถูกต้องหรือหมดอายุแล้ว',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'name'       => $token->name,
                'expires_at' => $token->expires_at->toISOString(),
                'scan_count' => $token->scan_count,
            ],
        ]);
    }

    /**
     * Verify label via PDA scan (public, token-based).
     */
    public function verify(Request $request): JsonResponse
    {
        $token = $this->resolveToken($request);

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Token ไม่ถูกต้องหรือหมดอายุแล้ว',
            ], 401);
        }

        $request->validate([
            'serial_number' => 'required|string',
        ]);

        $serial = trim($request->serial_number);
        $inv = Inventory::where('serial_number', $serial)->first();

        if (!$inv) {
            return response()->json([
                'success' => false,
                'message' => "ไม่พบ Serial: {$serial}",
            ], 404);
        }

        if (!$inv->label_printed_at) {
            return response()->json([
                'success' => false,
                'message' => 'Serial นี้ยังไม่ได้ปริ้น Label — ไม่สามารถยืนยันได้',
            ], 422);
        }

        if ($inv->label_verified_at) {
            return response()->json([
                'success' => false,
                'message' => 'Serial นี้ถูกยืนยันติด Label แล้วเมื่อ ' . $inv->label_verified_at->format('d/m/Y H:i'),
                'data'    => [
                    'serial_number' => $inv->serial_number,
                    'product_name'  => $inv->product?->name,
                    'product_code'  => $inv->product?->product_code,
                    'verified_at'   => $inv->label_verified_at->toISOString(),
                ],
            ], 422);
        }

        $now = now();

        $inv->update([
            'label_verified_at' => $now,
            'label_verified_by' => $token->created_by, // attribute to token creator
        ]);

        // Update token usage stats
        $token->increment('scan_count');
        $token->update(['last_used_at' => $now]);

        return response()->json([
            'success' => true,
            'message' => 'ยืนยันติด Label สำเร็จ',
            'data'    => [
                'serial_number' => $inv->serial_number,
                'product_name'  => $inv->product?->name,
                'product_code'  => $inv->product?->product_code,
                'verified_at'   => $now->toISOString(),
            ],
        ]);
    }

    /* ── Private helpers ── */

    private function resolveToken(Request $request): ?PdaToken
    {
        $tokenStr = $request->header('X-PDA-Token')
            ?? $request->query('token')
            ?? $request->input('token');

        if (!$tokenStr) {
            return null;
        }

        $token = PdaToken::where('token', $tokenStr)->first();

        if (!$token || !$token->isValid()) {
            return null;
        }

        return $token;
    }
}
