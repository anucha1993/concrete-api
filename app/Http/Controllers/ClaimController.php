<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Models\ClaimLine;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\PdaToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClaimController extends Controller
{
    /* ══════════════════════════════════════════════════════════
       LIST
       ══════════════════════════════════════════════════════════ */

    /**
     * GET /claims
     */
    public function index(Request $request): JsonResponse
    {
        $query = Claim::with(['creator:id,name', 'approver:id,name'])
            ->withCount('lines')
            ->withSum('lines', 'quantity');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('code', 'like', "%{$s}%")
                  ->orWhere('customer_name', 'like', "%{$s}%")
                  ->orWhere('reference_doc', 'like', "%{$s}%");
            });
        }

        $items = $query->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $items->items(),
            'meta'    => [
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
                'per_page'     => $items->perPage(),
                'total'        => $items->total(),
            ],
        ]);
    }

    /* ══════════════════════════════════════════════════════════
       SHOW
       ══════════════════════════════════════════════════════════ */

    /**
     * GET /claims/{id}
     */
    public function show(Claim $claim): JsonResponse
    {
        $claim->load([
            'creator:id,name',
            'approver:id,name',
            'stockDeduction:id,code',
            'lines.product:id,product_code,name,counting_unit',
            'lines.inventory:id,serial_number,status,condition,location_id',
            'lines.inventory.location:id,name,code',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $claim,
            'stats'   => [
                'total_qty'   => $claim->lines->sum('quantity'),
                'lines_count' => $claim->lines->count(),
            ],
        ]);
    }

    /* ══════════════════════════════════════════════════════════
       SEARCH ITEMS (ค้นหา Serial สำหรับเคลม)
       ══════════════════════════════════════════════════════════ */

    /**
     * GET /claims/search-items?q=xxx
     * ค้นหาจาก serial_number (barcode) — เคลมต้องมี serial
     */
    public function searchItems(Request $request): JsonResponse
    {
        $q = $request->input('q', '');
        if (strlen($q) < 1) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $inventories = Inventory::with(['product:id,product_code,name,counting_unit', 'location:id,name,code'])
            ->where('serial_number', 'like', "%{$q}%")
            ->where('status', 'SOLD')
            ->limit(30)
            ->get()
            ->map(fn ($inv) => [
                'inventory_id'  => $inv->id,
                'product_id'    => $inv->product_id,
                'product_code'  => $inv->product?->product_code,
                'product_name'  => $inv->product?->name,
                'counting_unit' => $inv->product?->counting_unit ?? 'ชิ้น',
                'serial_number' => $inv->serial_number,
                'status'        => $inv->status,
                'condition'     => $inv->condition,
                'location'      => $inv->location?->name,
            ]);

        return response()->json([
            'success' => true,
            'data'    => $inventories->values(),
        ]);
    }

    /* ══════════════════════════════════════════════════════════
       CREATE
       ══════════════════════════════════════════════════════════ */

    /**
     * POST /claims
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'type'                  => 'required|in:RETURN,TRANSPORT_DAMAGE,DEFECT,WRONG_SPEC,OTHER',
            'customer_name'         => 'nullable|string|max:255',
            'reference_doc'         => 'nullable|string|max:255',
            'stock_deduction_id'    => 'nullable|integer|exists:stock_deductions,id',
            'reason'                => 'nullable|string|max:1000',
            'note'                  => 'nullable|string|max:1000',
            'lines'                 => 'sometimes|array',
            'lines.*.serial_number' => 'required|string|max:50',
            'lines.*.resolution'    => 'nullable|in:RETURN_STOCK,RETURN_DAMAGED,REPLACE,REFUND,CREDIT_NOTE',
            'lines.*.note'          => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($request) {
            $claim = Claim::create([
                'code'               => Claim::generateCode(),
                'type'               => $request->type,
                'status'             => 'DRAFT',
                'customer_name'      => $request->customer_name,
                'reference_doc'      => $request->reference_doc,
                'stock_deduction_id' => $request->stock_deduction_id,
                'reason'             => $request->reason,
                'note'               => $request->note,
                'created_by'         => $request->user()->id,
            ]);

            if ($request->has('lines')) {
                foreach ($request->lines as $line) {
                    $serial = trim($line['serial_number']);
                    $inv = Inventory::where('serial_number', $serial)->first();

                    if (!$inv) {
                        throw new \Exception("ไม่พบ Serial: {$serial}");
                    }

                    if ($inv->status !== 'SOLD') {
                        throw new \Exception("Serial: {$serial} ไม่ใช่สถานะขายแล้ว (สถานะปัจจุบัน: {$inv->status})");
                    }

                    ClaimLine::create([
                        'claim_id'      => $claim->id,
                        'product_id'    => $inv->product_id,
                        'inventory_id'  => $inv->id,
                        'serial_number' => $serial,
                        'quantity'      => 1,
                        'resolution'    => $line['resolution'] ?? null,
                        'note'          => $line['note'] ?? null,
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'สร้างใบเคลมสำเร็จ',
                'data'    => $claim->load('creator:id,name', 'lines.product:id,product_code,name'),
            ], 201);
        });
    }

    /* ══════════════════════════════════════════════════════════
       UPDATE
       ══════════════════════════════════════════════════════════ */

    /**
     * PUT /claims/{id}
     */
    public function update(Request $request, Claim $claim): JsonResponse
    {
        if (in_array($claim->status, ['APPROVED', 'REJECTED', 'CANCELLED'])) {
            return response()->json(['success' => false, 'message' => 'ไม่สามารถแก้ไขได้ในสถานะนี้'], 422);
        }

        $request->validate([
            'type'                  => 'sometimes|in:RETURN,TRANSPORT_DAMAGE,DEFECT,WRONG_SPEC,OTHER',
            'customer_name'         => 'nullable|string|max:255',
            'reference_doc'         => 'nullable|string|max:255',
            'stock_deduction_id'    => 'nullable|integer|exists:stock_deductions,id',
            'reason'                => 'nullable|string|max:1000',
            'note'                  => 'nullable|string|max:1000',
            'lines'                 => 'sometimes|array',
            'lines.*.serial_number' => 'required|string|max:50',
            'lines.*.resolution'    => 'nullable|in:RETURN_STOCK,RETURN_DAMAGED,REPLACE,REFUND,CREDIT_NOTE',
            'lines.*.note'          => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($request, $claim) {
            $claim->update($request->only([
                'type', 'customer_name', 'reference_doc',
                'stock_deduction_id', 'reason', 'note',
            ]));

            if ($request->has('lines')) {
                $claim->lines()->delete();

                foreach ($request->lines as $line) {
                    $serial = trim($line['serial_number']);
                    $inv = Inventory::where('serial_number', $serial)->first();

                    if (!$inv) {
                        throw new \Exception("ไม่พบ Serial: {$serial}");
                    }

                    if ($inv->status !== 'SOLD') {
                        throw new \Exception("Serial: {$serial} ไม่ใช่สถานะขายแล้ว (สถานะปัจจุบัน: {$inv->status})");
                    }

                    ClaimLine::create([
                        'claim_id'      => $claim->id,
                        'product_id'    => $inv->product_id,
                        'inventory_id'  => $inv->id,
                        'serial_number' => $serial,
                        'quantity'      => 1,
                        'resolution'    => $line['resolution'] ?? null,
                        'note'          => $line['note'] ?? null,
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'แก้ไขใบเคลมสำเร็จ',
                'data'    => $claim->fresh()->load('creator:id,name', 'lines.product:id,product_code,name'),
            ]);
        });
    }

    /* ══════════════════════════════════════════════════════════
       DELETE (DRAFT only)
       ══════════════════════════════════════════════════════════ */

    public function destroy(Claim $claim): JsonResponse
    {
        if ($claim->status !== 'DRAFT') {
            return response()->json(['success' => false, 'message' => 'ลบได้เฉพาะสถานะแบบร่างเท่านั้น'], 422);
        }

        $claim->lines()->delete();
        $claim->delete();

        return response()->json(['success' => true, 'message' => 'ลบใบเคลมสำเร็จ']);
    }

    /* ══════════════════════════════════════════════════════════
       GENERATE PDA TOKEN (CRL)
       ══════════════════════════════════════════════════════════ */

    /**
     * POST /claims/{claim}/generate-pda
     * สร้าง CRL token เพื่อให้ PDA สแกน barcode
     */
    public function generatePda(Claim $claim): JsonResponse
    {
        if (in_array($claim->status, ['APPROVED', 'REJECTED', 'CANCELLED'])) {
            return response()->json(['success' => false, 'message' => 'ไม่สามารถสร้าง CRL ในสถานะนี้'], 422);
        }

        if ($claim->pda_token) {
            return response()->json([
                'success' => true,
                'message' => 'มี CRL token แล้ว',
                'data'    => ['pda_token' => $claim->pda_token],
            ]);
        }

        // สร้าง PdaToken record จริงใน pda_tokens table
        $pdaToken = PdaToken::create([
            'token'      => Str::random(48),
            'name'       => 'CRL — ' . $claim->code,
            'created_by' => request()->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        $claim->update(['pda_token' => $pdaToken->token]);

        return response()->json([
            'success' => true,
            'message' => 'สร้าง CRL สำเร็จ',
            'data'    => ['pda_token' => $pdaToken->token],
        ]);
    }

    /* ══════════════════════════════════════════════════════════
       WORKFLOW ACTIONS
       ══════════════════════════════════════════════════════════ */

    /**
     * POST /claims/{id}/submit  (DRAFT → PENDING)
     */
    public function submit(Claim $claim): JsonResponse
    {
        if ($claim->status !== 'DRAFT') {
            return response()->json(['success' => false, 'message' => 'ส่งตรวจสอบได้เฉพาะสถานะแบบร่าง'], 422);
        }

        if ($claim->lines()->count() === 0) {
            return response()->json(['success' => false, 'message' => 'ต้องมีรายการสินค้าอย่างน้อย 1 รายการ'], 422);
        }

        $claim->update(['status' => 'PENDING']);

        return response()->json([
            'success' => true,
            'message' => 'ส่งใบเคลมตรวจสอบสำเร็จ',
            'data'    => $claim->fresh()->load('creator:id,name', 'approver:id,name'),
        ]);
    }

    /**
     * POST /claims/{id}/approve  (PENDING → APPROVED)
     */
    public function approve(Request $request, Claim $claim): JsonResponse
    {
        if ($claim->status !== 'PENDING') {
            return response()->json(['success' => false, 'message' => 'อนุมัติได้เฉพาะสถานะรอตรวจสอบ'], 422);
        }

        $claim->load('lines');
        $missingResolution = $claim->lines->filter(fn ($l) => !$l->resolution)->count();
        if ($missingResolution > 0) {
            return response()->json([
                'success' => false,
                'message' => "กรุณาเลือกวิธีดำเนินการให้ครบทุกรายการ (ขาด {$missingResolution} รายการ)",
            ], 422);
        }

        return DB::transaction(function () use ($request, $claim) {
            $userId = $request->user()->id;
            $now    = now();
            $returned = 0;

            foreach ($claim->lines as $line) {
                if (!in_array($line->resolution, ['RETURN_STOCK', 'RETURN_DAMAGED'])) {
                    continue;
                }
                if (!$line->inventory_id) {
                    continue;
                }

                $inv = Inventory::where('id', $line->inventory_id)->lockForUpdate()->first();
                if (!$inv) continue;

                // resolution กำหนดสถานะ + สภาพ
                $targetStatus    = $line->resolution === 'RETURN_STOCK' ? 'IN_STOCK' : 'DAMAGED';
                $targetCondition = $line->resolution === 'RETURN_STOCK' ? 'GOOD' : 'DAMAGED';

                $inv->update([
                    'status'           => $targetStatus,
                    'condition'        => $targetCondition,
                    'last_movement_at' => $now,
                ]);

                InventoryMovement::create([
                    'inventory_id'     => $inv->id,
                    'type'             => 'CLAIM_RETURN',
                    'from_location_id' => null,
                    'to_location_id'   => $inv->location_id,
                    'reference_type'   => 'claims',
                    'reference_id'     => $claim->id,
                    'note'             => Claim::typeLabel($claim->type)
                                        . ' — ' . Claim::resolutionLabel($line->resolution)
                                        . " ({$claim->code})",
                    'created_by'       => $userId,
                ]);

                $returned++;
            }

            $claim->update([
                'status'      => 'APPROVED',
                'approved_by' => $userId,
                'approved_at' => $now,
            ]);

            $msg = 'อนุมัติใบเคลมสำเร็จ';
            if ($returned > 0) {
                $msg .= " — คืนสต๊อก {$returned} รายการ";
            }

            return response()->json([
                'success' => true,
                'message' => $msg,
                'data'    => $claim->fresh()->load('creator:id,name', 'approver:id,name'),
            ]);
        });
    }

    /**
     * POST /claims/{id}/reject  (PENDING → REJECTED)
     */
    public function reject(Request $request, Claim $claim): JsonResponse
    {
        if ($claim->status !== 'PENDING') {
            return response()->json(['success' => false, 'message' => 'ปฏิเสธได้เฉพาะสถานะรอตรวจสอบ'], 422);
        }

        $request->validate(['reject_reason' => 'required|string|max:1000']);

        $claim->update([
            'status'        => 'REJECTED',
            'reject_reason' => $request->reject_reason,
            'approved_by'   => $request->user()->id,
            'approved_at'   => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'ปฏิเสธใบเคลมแล้ว',
            'data'    => $claim->fresh()->load('creator:id,name', 'approver:id,name'),
        ]);
    }

    /**
     * POST /claims/{id}/cancel  (DRAFT|PENDING → CANCELLED)
     */
    public function cancel(Claim $claim): JsonResponse
    {
        if (!in_array($claim->status, ['DRAFT', 'PENDING'])) {
            return response()->json(['success' => false, 'message' => 'ยกเลิกได้เฉพาะสถานะแบบร่างหรือรอตรวจสอบ'], 422);
        }

        $claim->update(['status' => 'CANCELLED']);

        return response()->json([
            'success' => true,
            'message' => 'ยกเลิกใบเคลมแล้ว',
            'data'    => $claim->fresh()->load('creator:id,name', 'approver:id,name'),
        ]);
    }

    /* ══════════════════════════════════════════════════════════
       PDA ENDPOINTS (token-based, no auth)
       ══════════════════════════════════════════════════════════ */

    /**
     * GET /pda/claims/active
     */
    public function pdaActive(Request $request): JsonResponse
    {
        $pdaToken = $this->resolvePdaToken($request);
        if (!$pdaToken) {
            return response()->json(['success' => false, 'message' => 'Token ไม่ถูกต้อง'], 401);
        }

        $claims = Claim::whereIn('status', ['DRAFT', 'PENDING'])
            ->whereNotNull('pda_token')
            ->with(['lines.product:id,product_code,name'])
            ->withCount('lines')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($c) => [
                'id'            => $c->id,
                'code'          => $c->code,
                'type'          => $c->type,
                'type_label'    => Claim::typeLabel($c->type),
                'status'        => $c->status,
                'customer_name' => $c->customer_name,
                'lines_count'   => $c->lines_count,
                'total_qty'     => $c->lines->sum('quantity'),
                'lines'         => $c->lines->map(fn ($l) => [
                    'id'            => $l->id,
                    'serial_number' => $l->serial_number,
                    'product_name'  => $l->product->name ?? '-',
                    'product_code'  => $l->product->product_code ?? '-',
                ]),
                'created_at'    => $c->created_at->toISOString(),
            ]);

        return response()->json(['success' => true, 'data' => $claims]);
    }

    /**
     * GET /pda/claims/{id}/progress
     */
    public function pdaProgress(Request $request, int $id): JsonResponse
    {
        $pdaToken = $this->resolvePdaToken($request);
        if (!$pdaToken) {
            return response()->json(['success' => false, 'message' => 'Token ไม่ถูกต้อง'], 401);
        }

        $c = Claim::with('lines.product:id,product_code,name')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'            => $c->id,
                'code'          => $c->code,
                'type_label'    => Claim::typeLabel($c->type),
                'status'        => $c->status,
                'customer_name' => $c->customer_name,
                'total_qty'     => $c->lines->sum('quantity'),
                'lines'         => $c->lines->map(fn ($l) => [
                    'id'            => $l->id,
                    'serial_number' => $l->serial_number,
                    'product_name'  => $l->product->name ?? '-',
                    'product_code'  => $l->product->product_code ?? '-',
                ]),
            ],
        ]);
    }

    /**
     * GET /pda/claims/{id}/scans
     */
    public function pdaScans(Request $request, int $id): JsonResponse
    {
        $pdaToken = $this->resolvePdaToken($request);
        if (!$pdaToken) {
            return response()->json(['success' => false, 'message' => 'Token ไม่ถูกต้อง'], 401);
        }

        $lines = ClaimLine::where('claim_id', $id)
            ->with('product:id,product_code,name')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn ($l) => [
                'id'            => $l->id,
                'serial_number' => $l->serial_number,
                'product_name'  => $l->product?->name ?? '-',
                'product_code'  => $l->product?->product_code ?? '-',
                'resolution'    => $l->resolution,
                'scanned_at'    => $l->created_at->toISOString(),
            ]);

        return response()->json(['success' => true, 'data' => $lines]);
    }

    /**
     * POST /pda/claims/scan
     */
    public function pdaScan(Request $request): JsonResponse
    {
        $pdaToken = $this->resolvePdaToken($request);
        if (!$pdaToken) {
            return response()->json(['success' => false, 'message' => 'Token ไม่ถูกต้อง'], 401);
        }

        $request->validate([
            'claim_id'      => 'required|integer',
            'serial_number' => 'required|string',
            'resolution'    => 'nullable|in:RETURN_STOCK,RETURN_DAMAGED,REPLACE,REFUND,CREDIT_NOTE',
        ]);

        $serial = trim($request->serial_number);
        $claim = Claim::find($request->claim_id);

        if (!$claim || !in_array($claim->status, ['DRAFT', 'PENDING'])) {
            return response()->json(['success' => false, 'message' => 'ใบเคลมไม่พร้อมสแกน'], 422);
        }
        if (!$claim->pda_token) {
            return response()->json(['success' => false, 'message' => 'ใบเคลมยังไม่เปิด CRL'], 422);
        }

        return DB::transaction(function () use ($claim, $pdaToken, $serial) {
            $inv = Inventory::with('product:id,product_code,name')
                ->where('serial_number', $serial)
                ->lockForUpdate()
                ->first();

            if (!$inv) {
                return response()->json([
                    'success' => false,
                    'message' => "ไม่พบ Serial: {$serial}",
                    'status'  => 'NOT_FOUND',
                ], 404);
            }

            if ($inv->status !== 'SOLD') {
                return response()->json([
                    'success' => false,
                    'message' => "Serial {$serial} ไม่ใช่สถานะขายแล้ว (สถานะ: {$inv->status})",
                    'status'  => 'INVALID_STATUS',
                ], 422);
            }

            $exists = ClaimLine::where('claim_id', $claim->id)
                ->where('inventory_id', $inv->id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => "Serial {$serial} อยู่ในใบเคลมแล้ว",
                    'status'  => 'DUPLICATE',
                    'data'    => [
                        'serial_number' => $serial,
                        'product_name'  => $inv->product?->name,
                        'product_code'  => $inv->product?->product_code,
                    ],
                ], 422);
            }

            $line = ClaimLine::create([
                'claim_id'      => $claim->id,
                'product_id'    => $inv->product_id,
                'inventory_id'  => $inv->id,
                'serial_number' => $serial,
                'quantity'      => 1,
                'resolution'    => request('resolution'),
            ]);

            $pdaToken->increment('scan_count');
            $pdaToken->update(['last_used_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => 'สแกนสำเร็จ',
                'status'  => 'OK',
                'data'    => [
                    'line_id'       => $line->id,
                    'serial_number' => $serial,
                    'product_name'  => $inv->product?->name,
                    'product_code'  => $inv->product?->product_code,
                    'condition'     => $inv->condition ?? 'GOOD',
                    'status'        => $inv->status,
                    'total_lines'   => $claim->lines()->count(),
                ],
            ]);
        });
    }

    /**
     * PUT /pda/claims/scans/{lineId}/resolution
     */
    public function pdaUpdateResolution(Request $request, int $lineId): JsonResponse
    {
        $pdaToken = $this->resolvePdaToken($request);
        if (!$pdaToken) {
            return response()->json(['success' => false, 'message' => 'Token ไม่ถูกต้อง'], 401);
        }

        $request->validate([
            'resolution' => 'nullable|in:RETURN_STOCK,RETURN_DAMAGED,REPLACE,REFUND,CREDIT_NOTE',
        ]);

        $line = ClaimLine::findOrFail($lineId);
        $claim = Claim::find($line->claim_id);

        if (!$claim || !in_array($claim->status, ['DRAFT', 'PENDING'])) {
            return response()->json(['success' => false, 'message' => 'ไม่สามารถแก้ไขได้'], 422);
        }

        $line->update(['resolution' => $request->resolution]);

        return response()->json([
            'success' => true,
            'message' => 'บันทึกวิธีดำเนินการแล้ว',
        ]);
    }

    /**
     * DELETE /pda/claims/scans/{lineId}
     */
    public function pdaDeleteScan(Request $request, int $lineId): JsonResponse
    {
        $pdaToken = $this->resolvePdaToken($request);
        if (!$pdaToken) {
            return response()->json(['success' => false, 'message' => 'Token ไม่ถูกต้อง'], 401);
        }

        $line = ClaimLine::findOrFail($lineId);
        $claim = Claim::find($line->claim_id);

        if (!$claim || !in_array($claim->status, ['DRAFT', 'PENDING'])) {
            return response()->json(['success' => false, 'message' => 'ไม่สามารถลบได้'], 422);
        }

        $line->delete();

        return response()->json(['success' => true, 'message' => 'ลบรายการสำเร็จ']);
    }

    /* ── Private helpers ── */

    private function resolvePdaToken(Request $request): ?PdaToken
    {
        $tokenStr = $request->header('X-PDA-Token')
            ?? $request->query('token')
            ?? $request->input('token');

        if (!$tokenStr) return null;

        $token = PdaToken::where('token', $tokenStr)->first();
        return ($token && $token->isValid()) ? $token : null;
    }
}
