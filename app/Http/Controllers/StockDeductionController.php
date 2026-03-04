<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\PdaToken;
use App\Models\Product;
use App\Models\StockDeduction;
use App\Models\StockDeductionLine;
use App\Models\StockDeductionScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockDeductionController extends Controller
{
    /* ══════════════════════════════════════════════════════════
       ADMIN ENDPOINTS (auth:sanctum)
       ══════════════════════════════════════════════════════════ */

    /**
     * GET /stock-deductions
     */
    public function index(Request $request): JsonResponse
    {
        $query = StockDeduction::with(['creator:id,name', 'approver:id,name'])
            ->withCount('lines')
            ->withCount('scans')
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

    /**
     * GET /stock-deductions/{id}
     */
    public function show(StockDeduction $stockDeduction): JsonResponse
    {
        $stockDeduction->load([
            'creator:id,name',
            'approver:id,name',
            'lines.product:id,product_code,name,counting_unit',
            'scans.inventory:id,serial_number,status,condition,location_id',
            'scans.inventory.location:id,name,code',
            'scans.inventory.product:id,product_code,name',
        ]);

        $totalPlanned = $stockDeduction->lines->sum('quantity');
        $totalScanned = $stockDeduction->lines->sum('scanned_qty');

        return response()->json([
            'success' => true,
            'data'    => $stockDeduction,
            'stats'   => [
                'total_planned' => $totalPlanned,
                'total_scanned' => $totalScanned,
                'lines_count'   => $stockDeduction->lines->count(),
                'scans_count'   => $stockDeduction->scans->count(),
            ],
        ]);
    }

    /**
     * POST /stock-deductions
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'type'          => 'required|in:SOLD,LOST,DAMAGED,OTHER',
            'customer_name' => 'nullable|string|max:255',
            'reference_doc' => 'nullable|string|max:255',
            'reason'        => 'nullable|string|max:1000',
            'note'          => 'nullable|string|max:1000',
            'lines'         => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer|exists:products,id',
            'lines.*.quantity'   => 'required|integer|min:1',
            'lines.*.note'       => 'nullable|string|max:500',
        ]);

        // ตรวจสอบ stock ว่าเพียงพอหรือไม่ (จองตั้งแต่ DRAFT)
        $errors = [];
        foreach ($request->lines as $line) {
            $productId = (int) $line['product_id'];
            $qty = (int) $line['quantity'];

            $inStock = Inventory::where('product_id', $productId)
                ->where('status', 'IN_STOCK')
                ->count();

            // จำนวนที่ถูกจองจาก lines ของใบตัดสต๊อกอื่นที่ยังไม่อนุมัติ/ยกเลิก
            $reserved = (int) StockDeductionLine::where('product_id', $productId)
                ->whereHas('stockDeduction', fn ($q) => $q->whereNotIn('status', ['APPROVED', 'CANCELLED']))
                ->sum('quantity');

            $available = $inStock - $reserved;

            if ($qty > $available) {
                $product = Product::find($productId);
                $errors[] = "{$product->product_code} {$product->name}: ต้องการ {$qty} คงเหลือ {$available} (สต๊อก {$inStock} จอง {$reserved})";
            }
        }

        if (!empty($errors)) {
            return response()->json([
                'success' => false,
                'message' => 'สต๊อกไม่เพียงพอ: ' . implode(', ', $errors),
            ], 422);
        }

        return DB::transaction(function () use ($request) {
            $deduction = StockDeduction::create([
                'code'          => StockDeduction::generateCode(),
                'type'          => $request->type,
                'status'        => 'DRAFT',
                'customer_name' => $request->customer_name,
                'reference_doc' => $request->reference_doc,
                'reason'        => $request->reason,
                'note'          => $request->note,
                'created_by'    => $request->user()->id,
            ]);

            foreach ($request->lines as $line) {
                StockDeductionLine::create([
                    'stock_deduction_id' => $deduction->id,
                    'product_id'         => $line['product_id'],
                    'quantity'           => $line['quantity'],
                    'note'               => $line['note'] ?? null,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'สร้างใบตัดสต๊อกสำเร็จ',
                'data'    => $deduction->load('creator:id,name', 'lines.product:id,product_code,name'),
            ], 201);
        });
    }

    /**
     * PUT /stock-deductions/{id}
     */
    public function update(Request $request, StockDeduction $stockDeduction): JsonResponse
    {
        if (in_array($stockDeduction->status, ['APPROVED', 'CANCELLED'])) {
            return response()->json(['success' => false, 'message' => 'ไม่สามารถแก้ไขได้ในสถานะนี้'], 422);
        }

        $request->validate([
            'type'          => 'sometimes|in:SOLD,LOST,DAMAGED,OTHER',
            'customer_name' => 'nullable|string|max:255',
            'reference_doc' => 'nullable|string|max:255',
            'reason'        => 'nullable|string|max:1000',
            'note'          => 'nullable|string|max:1000',
            'lines'         => 'sometimes|array|min:1',
            'lines.*.product_id' => 'required|integer|exists:products,id',
            'lines.*.quantity'   => 'required|integer|min:1',
            'lines.*.note'       => 'nullable|string|max:500',
        ]);

        // ตรวจสอบ stock ว่าเพียงพอหรือไม่
        if ($request->has('lines')) {
            $errors = [];
            foreach ($request->lines as $line) {
                $productId = (int) $line['product_id'];
                $qty = (int) $line['quantity'];

                $inStock = Inventory::where('product_id', $productId)
                    ->where('status', 'IN_STOCK')
                    ->count();

                // จำนวนที่ถูกจองจาก lines ของใบตัดสต๊อกอื่น (ไม่นับใบนี้เอง)
                $reserved = (int) StockDeductionLine::where('product_id', $productId)
                    ->where('stock_deduction_id', '!=', $stockDeduction->id)
                    ->whereHas('stockDeduction', fn ($q) => $q->whereNotIn('status', ['APPROVED', 'CANCELLED']))
                    ->sum('quantity');

                $available = $inStock - $reserved;

                if ($qty > $available) {
                    $product = Product::find($productId);
                    $errors[] = "{$product->product_code} {$product->name}: ต้องการ {$qty} คงเหลือ {$available} (สต๊อก {$inStock} จอง {$reserved})";
                }
            }

            if (!empty($errors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'สต๊อกไม่เพียงพอ: ' . implode(', ', $errors),
                ], 422);
            }
        }

        return DB::transaction(function () use ($request, $stockDeduction) {
            $stockDeduction->update($request->only(['type', 'customer_name', 'reference_doc', 'reason', 'note']));

            if ($request->has('lines')) {
                if ($stockDeduction->status === 'DRAFT') {
                    // DRAFT: simple delete & recreate (no scans yet)
                    $stockDeduction->lines()->delete();
                    foreach ($request->lines as $line) {
                        StockDeductionLine::create([
                            'stock_deduction_id' => $stockDeduction->id,
                            'product_id'         => $line['product_id'],
                            'quantity'           => $line['quantity'],
                            'note'               => $line['note'] ?? null,
                        ]);
                    }
                } else {
                    // Has scan activity — smart update
                    $existingLines = $stockDeduction->lines()->get()->keyBy('product_id');
                    $incomingProductIds = collect($request->lines)->pluck('product_id')->map(fn ($v) => (int) $v)->toArray();

                    // Remove lines not in incoming payload
                    foreach ($existingLines as $productId => $existingLine) {
                        if (!in_array((int) $productId, $incomingProductIds, true)) {
                            if ($existingLine->scanned_qty > 0) {
                                return response()->json([
                                    'success' => false,
                                    'message' => "ไม่สามารถลบรายการ {$existingLine->product?->name} ได้ เพราะมีการสแกนแล้ว {$existingLine->scanned_qty} ชิ้น",
                                ], 422);
                            }
                            $existingLine->delete();
                        }
                    }

                    // Update existing / create new lines
                    foreach ($request->lines as $lineData) {
                        $existing = $existingLines->get((int) $lineData['product_id']);
                        if ($existing) {
                            if ((int) $lineData['quantity'] < $existing->scanned_qty) {
                                return response()->json([
                                    'success' => false,
                                    'message' => "จำนวนสินค้า {$existing->product?->name} ต้องไม่น้อยกว่าที่สแกนแล้ว ({$existing->scanned_qty} ชิ้น)",
                                ], 422);
                            }
                            $existing->update([
                                'quantity' => $lineData['quantity'],
                                'note'     => $lineData['note'] ?? null,
                            ]);
                        } else {
                            StockDeductionLine::create([
                                'stock_deduction_id' => $stockDeduction->id,
                                'product_id'         => $lineData['product_id'],
                                'quantity'           => $lineData['quantity'],
                                'note'               => $lineData['note'] ?? null,
                            ]);
                        }
                    }

                    // Re-check completion status
                    $stockDeduction->load('lines');
                    $allFulfilled = $stockDeduction->lines->every(fn ($l) => $l->scanned_qty >= $l->quantity);

                    if ($allFulfilled && $stockDeduction->lines->count() > 0 && $stockDeduction->status !== 'COMPLETED') {
                        $stockDeduction->update(['status' => 'COMPLETED']);
                    } elseif (!$allFulfilled && $stockDeduction->status === 'COMPLETED') {
                        $stockDeduction->update(['status' => 'IN_PROGRESS']);
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'อัพเดทสำเร็จ',
                'data'    => $stockDeduction->fresh()->load('creator:id,name', 'lines.product:id,product_code,name'),
            ]);
        });
    }

    /**
     * POST /stock-deductions/{id}/submit
     * DRAFT → PENDING (generates PDA link).
     */
    public function submit(StockDeduction $stockDeduction): JsonResponse
    {
        if ($stockDeduction->status !== 'DRAFT') {
            return response()->json(['success' => false, 'message' => 'ส่งได้เฉพาะสถานะแบบร่าง'], 422);
        }

        if ($stockDeduction->lines()->count() === 0) {
            return response()->json(['success' => false, 'message' => 'กรุณาเพิ่มรายการสินค้าก่อน'], 422);
        }

        $tokenStr = Str::random(32);

        // Create an actual PdaToken row so /pda/validate can find it
        $pdaTokenRecord = PdaToken::create([
            'token'      => $tokenStr,
            'name'       => 'ตัดสต๊อก ' . $stockDeduction->code,
            'created_by' => auth()->id(),
            'expires_at' => now()->addHours(24),
        ]);

        $stockDeduction->update([
            'status'    => 'PENDING',
            'pda_token' => $tokenStr,
        ]);

        return response()->json([
            'success'   => true,
            'message'   => 'ส่งใบตัดสต๊อกสำเร็จ — พร้อมให้สแกน',
            'pda_token' => $tokenStr,
            'data'      => $stockDeduction->fresh(),
        ]);
    }

    /**
     * POST /stock-deductions/{id}/approve
     * COMPLETED → APPROVED.
     */
    public function approve(Request $request, StockDeduction $stockDeduction): JsonResponse
    {
        if ($stockDeduction->status !== 'COMPLETED') {
            return response()->json(['success' => false, 'message' => 'อนุมัติได้เฉพาะสถานะสแกนครบ'], 422);
        }

        return DB::transaction(function () use ($request, $stockDeduction) {
            $userId = $request->user()->id;
            $now    = now();

            $stockDeduction->load('scans');

            $targetStatus = match ($stockDeduction->type) {
                'SOLD'    => 'SOLD',
                'LOST'    => 'SCRAPPED',
                'DAMAGED' => 'SCRAPPED',
                'OTHER'   => 'SCRAPPED',
            };

            $movementType = match ($stockDeduction->type) {
                'SOLD'    => 'SOLD',
                'LOST'    => 'ADJUSTMENT',
                'DAMAGED' => 'DAMAGED',
                'OTHER'   => 'ADJUSTMENT',
            };

            $deducted = 0;
            $skipped  = [];

            foreach ($stockDeduction->scans as $scan) {
                // Lock inventory row to prevent concurrent modification
                $inv = Inventory::where('id', $scan->inventory_id)->lockForUpdate()->first();

                if (!$inv || !in_array($inv->status, ['IN_STOCK', 'DAMAGED'])) {
                    $skipped[] = $scan->serial_number;
                    continue;
                }

                $inv->update([
                    'status'           => $targetStatus,
                    'last_movement_at' => $now,
                ]);

                InventoryMovement::create([
                    'inventory_id'     => $inv->id,
                    'type'             => $movementType,
                    'from_location_id' => $inv->location_id,
                    'to_location_id'   => null,
                    'reference_type'   => 'stock_deductions',
                    'reference_id'     => $stockDeduction->id,
                    'note'             => StockDeduction::typeLabel($stockDeduction->type)
                                        . ' — ' . ($stockDeduction->reason ?: 'ตัดสต๊อก')
                                        . " ({$stockDeduction->code})",
                    'created_by'       => $userId,
                ]);

                $deducted++;
            }

            if (!empty($skipped)) {
                $list = implode(', ', $skipped);
                return response()->json([
                    'success' => false,
                    'message' => "ไม่สามารถอนุมัติได้ — Serial ต่อไปนี้ไม่อยู่ในคลังแล้ว: {$list}",
                ], 422);
            }

            $stockDeduction->update([
                'status'      => 'APPROVED',
                'approved_by' => $userId,
                'approved_at' => $now,
            ]);

            return response()->json([
                'success' => true,
                'message' => "อนุมัติตัดสต๊อกสำเร็จ — ตัด {$deducted} รายการ",
                'data'    => $stockDeduction->fresh()->load('creator:id,name', 'approver:id,name'),
            ]);
        });
    }

    /**
     * POST /stock-deductions/{id}/cancel
     */
    public function cancel(StockDeduction $stockDeduction): JsonResponse
    {
        if (in_array($stockDeduction->status, ['APPROVED', 'CANCELLED'])) {
            return response()->json(['success' => false, 'message' => 'ไม่สามารถยกเลิกได้'], 422);
        }

        $stockDeduction->update(['status' => 'CANCELLED']);

        return response()->json([
            'success' => true,
            'message' => 'ยกเลิกใบตัดสต๊อกสำเร็จ',
        ]);
    }

    /**
     * POST /stock-deductions/{id}/complete
     */
    public function complete(StockDeduction $stockDeduction): JsonResponse
    {
        if (!in_array($stockDeduction->status, ['PENDING', 'IN_PROGRESS'])) {
            return response()->json(['success' => false, 'message' => 'ไม่สามารถดำเนินการได้'], 422);
        }

        $stockDeduction->load('lines');
        $unfulfilled = $stockDeduction->lines->filter(fn ($l) => $l->scanned_qty < $l->quantity);

        if ($unfulfilled->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'ยังสแกนไม่ครบทุกรายการ',
            ], 422);
        }

        $stockDeduction->update(['status' => 'COMPLETED']);

        return response()->json([
            'success' => true,
            'message' => 'สแกนครบทุกรายการ — พร้อมอนุมัติ',
        ]);
    }

    /**
     * POST /stock-deductions/{id}/scan
     * Admin scan — same logic as PDA but uses auth user.
     */
    public function adminScan(Request $request, StockDeduction $stockDeduction): JsonResponse
    {
        $request->validate([
            'serial_number' => 'required|string',
        ]);

        $serial = trim($request->serial_number);

        if (!in_array($stockDeduction->status, ['PENDING', 'IN_PROGRESS'])) {
            return response()->json(['success' => false, 'message' => 'ใบตัดสต๊อกไม่พร้อมสแกน'], 422);
        }

        return DB::transaction(function () use ($stockDeduction, $serial) {
            $stockDeduction->load('lines');

            $inv = Inventory::with('product:id,product_code,name')
                ->where('serial_number', $serial)
                ->lockForUpdate()
                ->first();

            if (!$inv) {
                return response()->json(['success' => false, 'message' => "ไม่พบ Serial: {$serial}"], 404);
            }

            if (!in_array($inv->status, ['IN_STOCK', 'DAMAGED'])) {
                return response()->json(['success' => false, 'message' => "Serial {$serial} ไม่อยู่ในคลัง (สถานะ: {$inv->status})"], 422);
            }

            $alreadyScanned = StockDeductionScan::where('stock_deduction_id', $stockDeduction->id)
                ->where('inventory_id', $inv->id)
                ->exists();

            if ($alreadyScanned) {
                return response()->json([
                    'success' => false,
                    'message' => "Serial {$serial} ถูกสแกนแล้ว",
                    'data'    => ['serial_number' => $serial, 'product_name' => $inv->product?->name],
                ], 422);
            }

            // ตรวจสอบว่า serial นี้ถูกจองในใบตัดสต๊อกอื่นที่ยังไม่ได้อนุมัติ/ยกเลิกหรือไม่
            $lockedByOther = StockDeductionScan::where('inventory_id', $inv->id)
                ->where('stock_deduction_id', '!=', $stockDeduction->id)
                ->whereHas('stockDeduction', fn ($q) => $q->whereNotIn('status', ['APPROVED', 'CANCELLED']))
                ->exists();

            if ($lockedByOther) {
                return response()->json([
                    'success' => false,
                    'message' => "Serial {$serial} ถูกจองในใบตัดสต๊อกอื่นอยู่",
                ], 422);
            }

            $line = $stockDeduction->lines->firstWhere('product_id', $inv->product_id);

            if (!$line) {
                return response()->json([
                    'success' => false,
                    'message' => "สินค้า {$inv->product?->name} ไม่อยู่ในรายการใบตัดสต๊อก",
                ], 422);
            }

            if ($line->scanned_qty >= $line->quantity) {
                return response()->json([
                    'success' => false,
                    'message' => "สินค้า {$inv->product?->name} สแกนครบจำนวนแล้ว ({$line->scanned_qty}/{$line->quantity})",
                ], 422);
            }

            $now = now();

            StockDeductionScan::create([
                'stock_deduction_id'      => $stockDeduction->id,
                'stock_deduction_line_id' => $line->id,
                'inventory_id'            => $inv->id,
                'serial_number'           => $serial,
                'pda_token_id'            => null,
                'scanned_at'              => $now,
            ]);

            $line->increment('scanned_qty');

            if ($stockDeduction->status === 'PENDING') {
                $stockDeduction->update(['status' => 'IN_PROGRESS']);
            }

            $stockDeduction->load('lines');
            $allFulfilled = $stockDeduction->lines->every(fn ($l) => $l->scanned_qty >= $l->quantity);

            if ($allFulfilled) {
                $stockDeduction->update(['status' => 'COMPLETED']);
            }

            return response()->json([
                'success' => true,
                'message' => 'สแกนสำเร็จ',
                'data'    => [
                    'serial_number'  => $serial,
                    'product_name'   => $inv->product?->name,
                    'product_code'   => $inv->product?->product_code,
                    'condition'      => $inv->condition ?? 'GOOD',
                    'line_progress'  => ($line->scanned_qty) . '/' . $line->quantity,
                    'all_fulfilled'  => $allFulfilled,
                ],
            ]);
        });
    }

    /**
     * DELETE /stock-deductions/{id}/scans/{scanId}
     * Admin delete scan.
     */
    public function adminDeleteScan(StockDeduction $stockDeduction, int $scanId): JsonResponse
    {
        if (in_array($stockDeduction->status, ['APPROVED', 'CANCELLED'])) {
            return response()->json(['success' => false, 'message' => 'ไม่สามารถลบได้'], 422);
        }

        $scan = StockDeductionScan::where('stock_deduction_id', $stockDeduction->id)
            ->where('id', $scanId)
            ->with('line')
            ->firstOrFail();

        DB::transaction(function () use ($scan, $stockDeduction) {
            $scan->line->decrement('scanned_qty');
            $scan->delete();

            if ($stockDeduction->status === 'COMPLETED') {
                $stockDeduction->update(['status' => 'IN_PROGRESS']);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'ลบการสแกนสำเร็จ',
        ]);
    }

    /* ══════════════════════════════════════════════════════════
       PDA ENDPOINTS (token-based, no auth)
       ══════════════════════════════════════════════════════════ */

    /**
     * GET /pda/stock-deductions/active
     */
    public function pdaActive(Request $request): JsonResponse
    {
        $pdaToken = $this->resolvePdaToken($request);
        if (!$pdaToken) {
            return response()->json(['success' => false, 'message' => 'Token ไม่ถูกต้อง'], 401);
        }

        $deductions = StockDeduction::whereIn('status', ['PENDING', 'IN_PROGRESS'])
            ->whereNotNull('pda_token')
            ->with(['lines.product:id,product_code,name,counting_unit'])
            ->withCount('scans')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($d) => [
                'id'             => $d->id,
                'code'           => $d->code,
                'type'           => $d->type,
                'type_label'     => StockDeduction::typeLabel($d->type),
                'status'         => $d->status,
                'customer_name'  => $d->customer_name,
                'total_planned'  => $d->lines->sum('quantity'),
                'total_scanned'  => $d->lines->sum('scanned_qty'),
                'scans_count'    => $d->scans_count,
                'lines'          => $d->lines->map(fn ($l) => [
                    'id'           => $l->id,
                    'product_name' => $l->product->name ?? '-',
                    'product_code' => $l->product->product_code ?? '-',
                    'quantity'     => $l->quantity,
                    'scanned_qty'  => $l->scanned_qty,
                ]),
                'created_at'     => $d->created_at->toISOString(),
            ]);

        return response()->json(['success' => true, 'data' => $deductions]);
    }

    /**
     * GET /pda/stock-deductions/{id}/progress
     */
    public function pdaProgress(Request $request, int $id): JsonResponse
    {
        $pdaToken = $this->resolvePdaToken($request);
        if (!$pdaToken) {
            return response()->json(['success' => false, 'message' => 'Token ไม่ถูกต้อง'], 401);
        }

        $d = StockDeduction::with('lines.product:id,product_code,name')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'             => $d->id,
                'code'           => $d->code,
                'type_label'     => StockDeduction::typeLabel($d->type),
                'status'         => $d->status,
                'total_planned'  => $d->lines->sum('quantity'),
                'total_scanned'  => $d->lines->sum('scanned_qty'),
                'lines'          => $d->lines->map(fn ($l) => [
                    'id'           => $l->id,
                    'product_name' => $l->product->name ?? '-',
                    'product_code' => $l->product->product_code ?? '-',
                    'quantity'     => $l->quantity,
                    'scanned_qty'  => $l->scanned_qty,
                    'unit'         => $l->product->counting_unit ?? 'ชิ้น',
                ]),
            ],
        ]);
    }

    /**
     * GET /pda/stock-deductions/{id}/scans
     */
    public function pdaScans(Request $request, int $id): JsonResponse
    {
        $pdaToken = $this->resolvePdaToken($request);
        if (!$pdaToken) {
            return response()->json(['success' => false, 'message' => 'Token ไม่ถูกต้อง'], 401);
        }

        $scans = StockDeductionScan::where('stock_deduction_id', $id)
            ->with(['inventory.product:id,product_code,name'])
            ->orderByDesc('scanned_at')
            ->limit(200)
            ->get()
            ->map(fn ($s) => [
                'id'            => $s->id,
                'serial_number' => $s->serial_number,
                'product_name'  => $s->inventory?->product?->name ?? '-',
                'product_code'  => $s->inventory?->product?->product_code ?? '-',
                'condition'     => $s->inventory?->condition ?? 'GOOD',
                'scanned_at'    => $s->scanned_at->toISOString(),
            ]);

        return response()->json(['success' => true, 'data' => $scans]);
    }

    /**
     * POST /pda/stock-deductions/scan
     */
    public function pdaScan(Request $request): JsonResponse
    {
        $pdaToken = $this->resolvePdaToken($request);
        if (!$pdaToken) {
            return response()->json(['success' => false, 'message' => 'Token ไม่ถูกต้อง'], 401);
        }

        $request->validate([
            'stock_deduction_id' => 'required|integer',
            'serial_number'      => 'required|string',
        ]);

        $serial = trim($request->serial_number);

        $deduction = StockDeduction::with('lines')->find($request->stock_deduction_id);
        if (!$deduction || !in_array($deduction->status, ['PENDING', 'IN_PROGRESS'])) {
            return response()->json(['success' => false, 'message' => 'ใบตัดสต๊อกไม่พร้อมสแกน'], 422);
        }

        return DB::transaction(function () use ($deduction, $pdaToken, $serial) {
            // lockForUpdate ภายใน transaction เพื่อป้องกัน race condition
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

            if (!in_array($inv->status, ['IN_STOCK', 'DAMAGED'])) {
                return response()->json([
                    'success' => false,
                    'message' => "Serial {$serial} ไม่อยู่ในคลัง (สถานะ: {$inv->status})",
                    'status'  => 'INVALID_STATUS',
                ], 422);
            }

            $alreadyScanned = StockDeductionScan::where('stock_deduction_id', $deduction->id)
                ->where('inventory_id', $inv->id)
                ->exists();

            if ($alreadyScanned) {
                return response()->json([
                    'success' => false,
                    'message' => "Serial {$serial} ถูกสแกนแล้ว",
                    'status'  => 'DUPLICATE',
                    'data'    => [
                        'serial_number' => $serial,
                        'product_name'  => $inv->product?->name,
                        'product_code'  => $inv->product?->product_code,
                    ],
                ], 422);
            }

            // ตรวจสอบว่า serial นี้ถูกสแกนในใบตัดสต๊อกอื่นที่ยังไม่ได้อนุมัติ/ยกเลิกหรือไม่
            $lockedByOther = StockDeductionScan::where('inventory_id', $inv->id)
                ->where('stock_deduction_id', '!=', $deduction->id)
                ->whereHas('stockDeduction', fn ($q) => $q->whereNotIn('status', ['APPROVED', 'CANCELLED']))
                ->exists();

            if ($lockedByOther) {
                return response()->json([
                    'success' => false,
                    'message' => "Serial {$serial} ถูกจองในใบตัดสต๊อกอื่นอยู่",
                    'status'  => 'LOCKED_BY_OTHER',
                ], 422);
            }

            $line = $deduction->lines->firstWhere('product_id', $inv->product_id);

            if (!$line) {
                return response()->json([
                    'success' => false,
                    'message' => "สินค้า {$inv->product?->name} ไม่อยู่ในรายการใบตัดสต๊อก",
                    'status'  => 'PRODUCT_NOT_IN_LIST',
                    'data'    => [
                        'serial_number' => $serial,
                        'product_name'  => $inv->product?->name,
                        'product_code'  => $inv->product?->product_code,
                    ],
                ], 422);
            }

            if ($line->scanned_qty >= $line->quantity) {
                return response()->json([
                    'success' => false,
                    'message' => "สินค้า {$inv->product?->name} สแกนครบจำนวนแล้ว ({$line->scanned_qty}/{$line->quantity})",
                    'status'  => 'LINE_FULFILLED',
                    'data'    => [
                        'serial_number' => $serial,
                        'product_name'  => $inv->product?->name,
                        'product_code'  => $inv->product?->product_code,
                    ],
                ], 422);
            }

            $now = now();

            StockDeductionScan::create([
                'stock_deduction_id'      => $deduction->id,
                'stock_deduction_line_id' => $line->id,
                'inventory_id'            => $inv->id,
                'serial_number'           => $serial,
                'pda_token_id'            => $pdaToken->id,
                'scanned_at'              => $now,
            ]);

            $line->increment('scanned_qty');

            $pdaToken->increment('scan_count');
            $pdaToken->update(['last_used_at' => $now]);

            if ($deduction->status === 'PENDING') {
                $deduction->update(['status' => 'IN_PROGRESS']);
            }

            $deduction->load('lines');
            $allFulfilled = $deduction->lines->every(fn ($l) => $l->scanned_qty >= $l->quantity);

            if ($allFulfilled) {
                $deduction->update(['status' => 'COMPLETED']);
            }

            return response()->json([
                'success' => true,
                'message' => 'สแกนสำเร็จ',
                'status'  => $allFulfilled ? 'COMPLETED' : 'OK',
                'data'    => [
                    'serial_number'  => $serial,
                    'product_name'   => $inv->product?->name,
                    'product_code'   => $inv->product?->product_code,
                    'condition'      => $inv->condition ?? 'GOOD',
                    'line_progress'  => ($line->scanned_qty) . '/' . $line->quantity,
                    'all_fulfilled'  => $allFulfilled,
                ],
            ]);
        });
    }

    /**
     * DELETE /pda/stock-deductions/scans/{scanId}
     */
    public function pdaDeleteScan(Request $request, int $scanId): JsonResponse
    {
        $pdaToken = $this->resolvePdaToken($request);
        if (!$pdaToken) {
            return response()->json(['success' => false, 'message' => 'Token ไม่ถูกต้อง'], 401);
        }

        $scan = StockDeductionScan::with('line')->findOrFail($scanId);
        $deduction = StockDeduction::find($scan->stock_deduction_id);

        if (!$deduction || !in_array($deduction->status, ['PENDING', 'IN_PROGRESS', 'COMPLETED'])) {
            return response()->json(['success' => false, 'message' => 'ไม่สามารถลบได้'], 422);
        }

        DB::transaction(function () use ($scan, $deduction) {
            $scan->line->decrement('scanned_qty');
            $scan->delete();

            if ($deduction->status === 'COMPLETED') {
                $deduction->update(['status' => 'IN_PROGRESS']);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'ลบการสแกนสำเร็จ',
        ]);
    }

    /**
     * DELETE /stock-deductions/{stockDeduction}
     * ลบใบตัดสต๊อก — เฉพาะสถานะ DRAFT เท่านั้น
     */
    public function destroy(StockDeduction $stockDeduction): JsonResponse
    {
        if ($stockDeduction->status !== 'DRAFT') {
            return response()->json([
                'success' => false,
                'message' => 'ลบได้เฉพาะใบตัดสต๊อกที่เป็นแบบร่าง (DRAFT) เท่านั้น',
            ], 422);
        }

        return DB::transaction(function () use ($stockDeduction) {
            $stockDeduction->lines()->delete();
            $stockDeduction->delete();

            return response()->json([
                'success' => true,
                'message' => 'ลบใบตัดสต๊อกสำเร็จ',
            ]);
        });
    }

    /**
     * POST /stock-deductions/{id}/generate-print-token
     * สร้าง/รีเฟรช PDA token สำหรับปริ้น (24 ชม.) — เฉพาะสถานะที่ยังไม่เสร็จ
     */
    public function generatePrintToken(StockDeduction $stockDeduction): JsonResponse
    {
        // ถ้าเสร็จแล้ว / ยกเลิก → ไม่ต้องสร้าง token
        if (in_array($stockDeduction->status, ['APPROVED', 'CANCELLED'])) {
            return response()->json([
                'success'   => true,
                'pda_token' => null,
                'message'   => 'เอกสารนี้ไม่ต้องสแกนแล้ว',
            ]);
        }

        // ถ้ามี token อยู่แล้วและยังไม่หมดอายุ → ใช้ตัวเดิมแต่ต่ออายุ 24 ชม.
        if ($stockDeduction->pda_token) {
            $existingToken = PdaToken::where('token', $stockDeduction->pda_token)->first();
            if ($existingToken) {
                $newExpiry = now()->addHours(24);
                $existingToken->update(['expires_at' => $newExpiry]);
                return response()->json([
                    'success'    => true,
                    'pda_token'  => $stockDeduction->pda_token,
                    'expires_at' => $newExpiry->toIso8601String(),
                    'message'    => 'ต่ออายุ Token 24 ชม.',
                ]);
            }
        }

        // สร้าง token ใหม่
        $tokenStr  = Str::random(32);
        $expiresAt = now()->addHours(24);

        PdaToken::create([
            'token'      => $tokenStr,
            'name'       => 'ปริ้น ตัดสต๊อก ' . $stockDeduction->code,
            'created_by' => auth()->id(),
            'expires_at' => $expiresAt,
        ]);

        $stockDeduction->update(['pda_token' => $tokenStr]);

        // ถ้ายังเป็น DRAFT → เปลี่ยนเป็น PENDING ด้วย
        if ($stockDeduction->status === 'DRAFT' && $stockDeduction->lines()->count() > 0) {
            $stockDeduction->update(['status' => 'PENDING']);
        }

        return response()->json([
            'success'    => true,
            'pda_token'  => $tokenStr,
            'expires_at' => $expiresAt->toIso8601String(),
            'message'    => 'สร้าง Token สำเร็จ (24 ชม.)',
        ]);
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
