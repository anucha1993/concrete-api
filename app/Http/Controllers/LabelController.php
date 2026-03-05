<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\LabelPrintLog;
use App\Models\LabelReprintRequest;
use App\Models\ProductionSerial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LabelController extends Controller
{
    /**
     * GET /labels/production-orders
     * List production orders grouped with label-print counts.
     */
    public function productionOrders(Request $request): JsonResponse
    {
        $query = \App\Models\ProductionOrder::query()
            ->with(['pack:id,code,name', 'creator:id,name'])
            ->where('status', '!=', 'CANCELLED')
            ->withCount([
                'serials as total_serials',
                'serials as printed_serials' => function ($q) {
                    $q->whereHas('inventory', fn ($inv) => $inv->where('label_print_count', '>', 0));
                },
                'serials as verified_serials' => function ($q) {
                    $q->whereHas('inventory', fn ($inv) => $inv->whereNotNull('label_verified_at'));
                },
            ]);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('order_number', 'like', "%{$s}%")
                  ->orWhereHas('pack', fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $orders->items(),
            'meta'    => [
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
            ],
        ]);
    }

    /**
     * GET /labels/production-orders/{id}/serials
     * List serials for a specific PO with label status.
     */
    public function productionOrderSerials(Request $request, int $id): JsonResponse
    {
        $po = \App\Models\ProductionOrder::findOrFail($id);

        $query = Inventory::with(['product:id,product_code,name,category_id,length,length_unit,thickness,thickness_unit,width', 'product.category:id,name', 'location:id,name,code'])
            ->where('production_order_id', $po->id);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('serial_number', 'like', "%{$s}%")
                  ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('product_code', 'like', "%{$s}%"));
            });
        }

        if ($request->filled('label_status')) {
            match ($request->label_status) {
                'not_printed' => $query->where('label_print_count', 0),
                'printed'     => $query->where('label_print_count', '>', 0)->whereNull('label_verified_at'),
                'verified'    => $query->whereNotNull('label_verified_at'),
                default       => null,
            };
        }

        $items = $query->orderBy('id', 'desc')
            ->paginate($request->integer('per_page', 30));

        return response()->json([
            'success' => true,
            'data'    => $items->items(),
            'meta'    => [
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
                'per_page'     => $items->perPage(),
                'total'        => $items->total(),
            ],
            'po'      => [
                'id'            => $po->id,
                'order_number'  => $po->order_number,
                'status'        => $po->status,
                'total_serials' => Inventory::where('production_order_id', $po->id)->count(),
                'printed'       => Inventory::where('production_order_id', $po->id)->where('label_print_count', '>', 0)->count(),
                'verified'      => Inventory::where('production_order_id', $po->id)->whereNotNull('label_verified_at')->count(),
            ],
        ]);
    }

    /**
     * POST /labels/print-by-po
     * Print all unprinted serials for a production order.
     * Body: { production_order_id, paper_size? }
     */
    public function printByProductionOrder(Request $request): JsonResponse
    {
        $request->validate([
            'production_order_id' => 'required|integer|exists:production_orders,id',
            'paper_size'          => 'sometimes|string|max:30',
        ]);

        $paperSize = $request->input('paper_size', '50x30');
        $userId    = $request->user()->id;
        $now       = now();

        // Restrict printing to IN_PROGRESS orders only (admin can bypass)
        $po = \App\Models\ProductionOrder::findOrFail($request->production_order_id);
        if ($po->status !== 'IN_PROGRESS' && !$request->user()->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'ใบสั่งผลิตต้องอยู่ในสถานะ "กำลังผลิต" ถึงจะปริ้น Label ได้',
            ], 422);
        }

        return DB::transaction(function () use ($request, $paperSize, $userId, $now) {
            $inventories = Inventory::where('production_order_id', $request->production_order_id)
                ->where('label_print_count', 0)
                ->lockForUpdate()
                ->get();

            if ($inventories->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'ไม่มี serial ที่ยังไม่ปริ้นในใบสั่งผลิตนี้',
                ], 422);
            }

            $count = 0;
            foreach ($inventories as $inv) {
                $inv->update([
                    'label_printed_at'  => $now,
                    'label_printed_by'  => $userId,
                    'label_print_count' => 1,
                ]);

                LabelPrintLog::create([
                    'inventory_id'  => $inv->id,
                    'serial_number' => $inv->serial_number,
                    'print_type'    => 'FIRST',
                    'paper_size'    => $paperSize,
                    'printed_by'    => $userId,
                    'printed_at'    => $now,
                ]);

                $count++;
            }

            return response()->json([
                'success'       => true,
                'message'       => "ปริ้น Label สำเร็จ {$count} รายการ (ทั้งใบสั่งผลิต)",
                'printed_count' => $count,
            ]);
        });
    }

    /**
     * GET /labels/printable
     * List serials available for first-time printing (never printed before).
     * Supports: ?production_order_id=X, ?search=, ?page=, ?per_page=
     */
    public function printable(Request $request): JsonResponse
    {
        $query = Inventory::with(['product:id,product_code,name,category_id,length,length_unit,thickness,thickness_unit,width', 'product.category:id,name', 'location:id,name,code'])
            ->where('label_print_count', 0);

        if ($request->filled('production_order_id')) {
            $query->where('production_order_id', $request->production_order_id);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('serial_number', 'like', "%{$s}%")
                  ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('product_code', 'like', "%{$s}%"));
            });
        }

        $items = $query->orderBy('id', 'desc')
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
     * POST /labels/print
     * Print barcode labels for given serial IDs (first-time only).
     * Body: { inventory_ids: [1,2,...], paper_size: "50x30" }
     */
    public function print(Request $request): JsonResponse
    {
        $request->validate([
            'inventory_ids'   => 'required|array|min:1',
            'inventory_ids.*' => 'integer|exists:inventories,id',
            'paper_size'      => 'sometimes|string|max:30',
        ]);

        $paperSize = $request->input('paper_size', '50x30');
        $userId    = $request->user()->id;
        $now       = now();

        return DB::transaction(function () use ($request, $paperSize, $userId, $now) {
            // Lock rows to prevent concurrent prints
            $inventories = Inventory::whereIn('id', $request->inventory_ids)
                ->lockForUpdate()
                ->get();

            // Check PO status — must be IN_PROGRESS to print first time (admin can bypass)
            $poIds = $inventories->pluck('production_order_id')->filter()->unique();
            if ($poIds->isNotEmpty() && !$request->user()->isAdmin()) {
                $invalidPOs = \App\Models\ProductionOrder::whereIn('id', $poIds)
                    ->where('status', '!=', 'IN_PROGRESS')
                    ->pluck('order_number');
                if ($invalidPOs->isNotEmpty()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'ใบสั่งผลิต ' . $invalidPOs->join(', ') . ' ไม่อยู่ในสถานะ "กำลังผลิต" — ไม่สามารถปริ้น Label ได้',
                    ], 422);
                }
            }

            // Check for already-printed items
            $alreadyPrinted = $inventories->filter(fn ($inv) => $inv->label_print_count > 0);
            $isAdmin = $request->user()->isAdmin();

            if ($alreadyPrinted->isNotEmpty() && !$isAdmin) {
                $serials = $alreadyPrinted->pluck('serial_number')->join(', ');
                return response()->json([
                    'success' => false,
                    'message' => "Serial เหล่านี้เคยปริ้นแล้ว: {$serials} — กรุณาส่งคำขอปริ้นซ้ำ",
                    'already_printed' => $alreadyPrinted->pluck('serial_number'),
                ], 422);
            }

            $reprintReason = $request->input('reprint_reason');
            $logs = [];

            foreach ($inventories as $inv) {
                $isReprint = $inv->label_print_count > 0;

                $inv->update([
                    'label_printed_at'  => $now,
                    'label_printed_by'  => $userId,
                    'label_print_count' => $inv->label_print_count + 1,
                ]);

                $logs[] = LabelPrintLog::create([
                    'inventory_id'   => $inv->id,
                    'serial_number'  => $inv->serial_number,
                    'print_type'     => $isReprint ? 'REPRINT' : 'FIRST',
                    'paper_size'     => $paperSize,
                    'reprint_reason' => $isReprint ? ($reprintReason ?? 'Admin ปริ้นซ้ำโดยตรง') : null,
                    'printed_by'     => $userId,
                    'printed_at'     => $now,
                ]);
            }

            return response()->json([
                'success'      => true,
                'message'      => 'ปริ้น Label สำเร็จ ' . count($logs) . ' รายการ',
                'printed_count' => count($logs),
                'data'         => $inventories->load(['product:id,product_code,name,category_id,length,length_unit,thickness,thickness_unit,width', 'product.category:id,name']),
            ]);
        });
    }

    /**
     * POST /labels/verify
     * PDA scans barcode to confirm label is attached.
     * Body: { serial_number: "E-2602-00000001" }
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'serial_number' => 'required|string|exists:inventories,serial_number',
        ]);

        $inv = Inventory::where('serial_number', $request->serial_number)->firstOrFail();

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
            ], 422);
        }

        $now = now();
        $userId = $request->user()->id;

        $inv->update([
            'label_verified_at' => $now,
            'label_verified_by' => $userId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'ยืนยันติด Label สำเร็จ — รอรับเข้าคลังจากใบสั่งผลิต',
            'data'    => $inv->load(['product:id,product_code,name,category_id,length,length_unit,thickness,thickness_unit,width', 'product.category:id,name', 'location:id,name,code']),
        ]);
    }

    /**
     * Auto-receive a single verified inventory item:
     * - Update ProductionSerial condition → GOOD
     * - Increment ProductionOrderItem good_qty
     * - Create InventoryMovement
     * - Auto-complete order if all items accounted for
     */
    private function autoReceiveVerifiedItem(Inventory $inv, int $userId, $now): void
    {
        $serial = ProductionSerial::where('inventory_id', $inv->id)->first();
        if (!$serial) return;

        $serial->update(['condition' => 'GOOD']);

        // Increment good_qty on the order item
        $serial->orderItem?->increment('good_qty');

        // Create inventory movement
        InventoryMovement::create([
            'inventory_id'     => $inv->id,
            'type'             => 'PRODUCTION_IN',
            'from_location_id' => null,
            'to_location_id'   => $inv->location_id,
            'reference_type'   => 'production_orders',
            'reference_id'     => $inv->production_order_id,
            'note'             => 'รับเข้าคลังอัตโนมัติจากการยืนยัน Label',
            'created_by'       => $userId,
        ]);

        // Check if order is fully received → auto-complete
        $this->checkAutoCompleteOrder($serial->production_order_id);
    }

    /**
     * Check if all items in a production order are fully accounted (good + damaged = planned).
     * If so, mark the order as COMPLETED.
     */
    private function checkAutoCompleteOrder(int $orderId): void
    {
        $order = \App\Models\ProductionOrder::with('items')->find($orderId);
        if (!$order || !in_array($order->status, ['IN_PROGRESS', 'CONFIRMED'])) return;

        $allDone = true;
        foreach ($order->items as $item) {
            if (($item->good_qty + $item->damaged_qty) < $item->planned_qty) {
                $allDone = false;
                break;
            }
        }

        if ($allDone) {
            $order->update([
                'status'       => 'COMPLETED',
                'completed_at' => now(),
            ]);
        }
    }

    /**
     * POST /labels/verify-batch
     * PDA scans multiple barcodes at once.
     * Body: { serial_numbers: ["E-2602-00000001", ...] }
     */
    public function verifyBatch(Request $request): JsonResponse
    {
        $request->validate([
            'serial_numbers'   => 'required|array|min:1',
            'serial_numbers.*' => 'string',
        ]);

        $now    = now();
        $userId = $request->user()->id;
        $results = ['verified' => [], 'skipped' => [], 'errors' => []];

        foreach ($request->serial_numbers as $serial) {
            $inv = Inventory::where('serial_number', $serial)->first();

            if (!$inv) {
                $results['errors'][] = "{$serial}: ไม่พบ serial";
                continue;
            }
            if (!$inv->label_printed_at) {
                $results['errors'][] = "{$serial}: ยังไม่ได้ปริ้น";
                continue;
            }
            if ($inv->label_verified_at) {
                $results['skipped'][] = $serial;
                continue;
            }

            $inv->update([
                'label_verified_at' => $now,
                'label_verified_by' => $userId,
            ]);

            $results['verified'][] = $serial;
        }

        return response()->json([
            'success' => true,
            'message' => 'ยืนยันติด Label ' . count($results['verified']) . ' รายการ',
            'data'    => $results,
        ]);
    }

    /**
     * GET /labels/history
     * Print history with filters.
     */
    public function history(Request $request): JsonResponse
    {
        $query = LabelPrintLog::with([
            'inventory:id,serial_number,product_id',
            'inventory.product:id,product_code,name,category_id,length,length_unit,thickness,thickness_unit,width',
            'inventory.product.category:id,name',
            'printer:id,name',
        ]);

        if ($request->filled('print_type')) {
            $query->where('print_type', $request->print_type);
        }
        if ($request->filled('production_order_id')) {
            $query->whereHas('inventory', fn ($q) => $q->where('production_order_id', $request->production_order_id));
        }
        if ($request->filled('search')) {
            $query->where('serial_number', 'like', "%{$request->search}%");
        }

        $logs = $query->orderBy('printed_at', 'desc')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $logs->items(),
            'meta'    => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'per_page'     => $logs->perPage(),
                'total'        => $logs->total(),
            ],
        ]);
    }

    /* ══════════════════════════════════════════════════════
       Reprint Request Workflow
       ══════════════════════════════════════════════════════ */

    /**
     * POST /labels/reprint-requests
     * Create a reprint request. Body: { reason, inventory_ids[], production_order_id? }
     */
    public function createReprintRequest(Request $request): JsonResponse
    {
        $request->validate([
            'reason'              => 'required|string|min:5|max:1000',
            'inventory_ids'       => 'required|array|min:1',
            'inventory_ids.*'     => 'integer|exists:inventories,id',
            'production_order_id' => 'sometimes|nullable|integer|exists:production_orders,id',
        ]);

        // All requested serials must have been printed at least once
        $neverPrinted = Inventory::whereIn('id', $request->inventory_ids)
            ->where('label_print_count', 0)
            ->pluck('serial_number');

        if ($neverPrinted->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Serial เหล่านี้ยังไม่เคยปริ้น ไม่ต้องส่งคำขอปริ้นซ้ำ: ' . $neverPrinted->join(', '),
            ], 422);
        }

        $rr = LabelReprintRequest::create([
            'reason'              => $request->reason,
            'requested_by'        => $request->user()->id,
            'production_order_id' => $request->production_order_id,
            'status'              => 'PENDING',
        ]);

        $rr->inventories()->attach($request->inventory_ids);
        $rr->load(['inventories:inventories.id,serial_number,product_id', 'requester:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'ส่งคำขอปริ้นซ้ำสำเร็จ',
            'data'    => $rr,
        ], 201);
    }

    /**
     * GET /labels/reprint-requests
     * List requests (all for managers, own for others).
     */
    public function reprintRequests(Request $request): JsonResponse
    {
        $query = LabelReprintRequest::with([
            'requester:id,name',
            'approver:id,name',
            'inventories:inventories.id,serial_number',
        ])->withCount('inventories');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $items = $query->orderBy('created_at', 'desc')
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
     * GET /labels/reprint-requests/{id}
     */
    public function showReprintRequest(LabelReprintRequest $reprintRequest): JsonResponse
    {
        $reprintRequest->load([
            'requester:id,name',
            'approver:id,name',
            'inventories:inventories.id,serial_number,product_id',
            'inventories.product:id,product_code,name,category_id,length,length_unit,thickness,thickness_unit,width',
            'inventories.product.category:id,name',
            'productionOrder:id,order_number',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $reprintRequest,
        ]);
    }

    /**
     * POST /labels/reprint-requests/{id}/approve
     */
    public function approveReprint(LabelReprintRequest $reprintRequest): JsonResponse
    {
        if ($reprintRequest->status !== 'PENDING') {
            return response()->json([
                'success' => false,
                'message' => 'คำขอนี้ถูกดำเนินการแล้ว',
            ], 422);
        }

        $reprintRequest->update([
            'status'      => 'APPROVED',
            'approved_by' => request()->user()->id,
            'approved_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'อนุมัติคำขอปริ้นซ้ำสำเร็จ',
            'data'    => $reprintRequest->load('approver:id,name'),
        ]);
    }

    /**
     * POST /labels/reprint-requests/{id}/reject
     * Body: { reject_reason: "..." }
     */
    public function rejectReprint(Request $request, LabelReprintRequest $reprintRequest): JsonResponse
    {
        $request->validate([
            'reject_reason' => 'required|string|min:5|max:500',
        ]);

        if ($reprintRequest->status !== 'PENDING') {
            return response()->json([
                'success' => false,
                'message' => 'คำขอนี้ถูกดำเนินการแล้ว',
            ], 422);
        }

        $reprintRequest->update([
            'status'        => 'REJECTED',
            'reject_reason' => $request->reject_reason,
            'approved_by'   => $request->user()->id,
            'approved_at'   => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'ปฏิเสธคำขอปริ้นซ้ำแล้ว',
            'data'    => $reprintRequest,
        ]);
    }

    /**
     * POST /labels/reprint
     * Execute reprint from an APPROVED request.
     * Body: { reprint_request_id, inventory_ids[], paper_size? }
     */
    public function reprint(Request $request): JsonResponse
    {
        $request->validate([
            'reprint_request_id' => 'required|integer|exists:label_reprint_requests,id',
            'inventory_ids'      => 'required|array|min:1',
            'inventory_ids.*'    => 'integer|exists:inventories,id',
            'paper_size'         => 'sometimes|string|max:30',
        ]);

        $rr = LabelReprintRequest::findOrFail($request->reprint_request_id);

        if ($rr->status !== 'APPROVED') {
            return response()->json([
                'success' => false,
                'message' => $rr->status === 'PRINTED'
                    ? 'คำขอนี้ถูกปริ้นซ้ำไปแล้ว ไม่สามารถปริ้นซ้ำได้อีก'
                    : 'คำขอนี้ยังไม่ได้รับการอนุมัติ',
            ], 422);
        }

        // Verify requested IDs are actually in the reprint request
        $allowedIds = $rr->inventories()->pluck('inventories.id')->toArray();
        $invalidIds = array_diff($request->inventory_ids, $allowedIds);
        if (!empty($invalidIds)) {
            return response()->json([
                'success' => false,
                'message' => 'มี Serial ที่ไม่ได้อยู่ในคำขอปริ้นซ้ำ',
            ], 422);
        }

        $paperSize = $request->input('paper_size', '50x30');
        $userId    = $request->user()->id;
        $now       = now();

        return DB::transaction(function () use ($request, $rr, $paperSize, $userId, $now) {
            $inventories = Inventory::whereIn('id', $request->inventory_ids)
                ->lockForUpdate()
                ->get();

            foreach ($inventories as $inv) {
                $inv->update([
                    'label_printed_at'  => $now,
                    'label_printed_by'  => $userId,
                    'label_print_count' => $inv->label_print_count + 1,
                ]);

                LabelPrintLog::create([
                    'inventory_id'      => $inv->id,
                    'serial_number'     => $inv->serial_number,
                    'print_type'        => 'REPRINT',
                    'paper_size'        => $paperSize,
                    'reprint_reason'    => $rr->reason,
                    'reprint_request_id' => $rr->id,
                    'printed_by'        => $userId,
                    'printed_at'        => $now,
                ]);
            }

            // Mark request as PRINTED — can only print once
            $rr->update(['status' => 'PRINTED']);

            return response()->json([
                'success'       => true,
                'message'       => 'ปริ้นซ้ำสำเร็จ ' . $inventories->count() . ' รายการ',
                'printed_count' => $inventories->count(),
                'data'          => $inventories->load(['product:id,product_code,name,category_id,length,length_unit,thickness,thickness_unit,width', 'product.category:id,name']),
            ]);
        });
    }

    /**
     * GET /labels/stats
     * Quick overview stats for the label page.
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'total_serials'      => Inventory::count(),
                'never_printed'      => Inventory::where('label_print_count', 0)->count(),
                'printed_not_verified' => Inventory::whereNotNull('label_printed_at')->whereNull('label_verified_at')->count(),
                'verified'           => Inventory::whereNotNull('label_verified_at')->count(),
                'pending_reprints'   => LabelReprintRequest::where('status', 'PENDING')->count(),
            ],
        ]);
    }
}
