<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\PdaToken;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\StockCountScan;
use App\Models\StockCountSerialResolution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockCountController extends Controller
{
    /**
     * สถานะที่ "ยังอยู่ในคลังจริง" — ใช้นับสต๊อก
     * IN_STOCK = ปกติ, RESERVED = จอง, DAMAGED = ชำรุด
     */
    private const COUNTABLE_STATUSES = ['IN_STOCK', 'RESERVED', 'DAMAGED'];

    /* ══════════════════════════════════════════════════════════════
       Admin CRUD (auth:sanctum)
       ══════════════════════════════════════════════════════════════ */

    /**
     * List stock counts.
     */
    public function index(Request $request): JsonResponse
    {
        $query = StockCount::with('creator:id,name')
            ->withCount(['items', 'scans']);

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
                  ->orWhere('name', 'like', "%{$s}%");
            });
        }

        $data = $query->orderByDesc('created_at')
            ->paginate($request->input('per_page', 15));

        return response()->json($data);
    }

    /**
     * Show a single stock count with items + stats.
     */
    public function show(StockCount $stockCount): JsonResponse
    {
        $stockCount->load([
            'creator:id,name',
            'approver:id,name',
            'items.product:id,product_code,name',
        ]);

        // Real-time stats from scans table (scanned_qty on items is only finalized on complete)
        $liveScanned = $stockCount->scans()->where('is_duplicate', false)->count();
        $liveUnexpected = $stockCount->scans()->where('is_expected', false)->where('is_duplicate', false)->count();

        // Per-product scanned counts for items (count all non-duplicate scans with matching product)
        $itemProductIds = $stockCount->items->pluck('product_id')->toArray();
        $scannedByProduct = $stockCount->scans()
            ->where('is_duplicate', false)
            ->whereIn('product_id', $itemProductIds)
            ->selectRaw('product_id, COUNT(*) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        // Enrich items with live scanned_qty when still IN_PROGRESS
        if ($stockCount->status === 'IN_PROGRESS') {
            foreach ($stockCount->items as $item) {
                $live = $scannedByProduct->get($item->product_id, 0);
                $item->scanned_qty = $live;
                $item->difference = $live - $item->expected_qty;
            }
        }

        $stats = [
            'total_expected'   => $stockCount->items->sum('expected_qty'),
            'total_scanned'    => $stockCount->status === 'IN_PROGRESS'
                ? $scannedByProduct->sum()
                : $stockCount->items->sum('scanned_qty'),
            'total_scans'      => $stockCount->scans()->count(),
            'matched'          => $stockCount->items->where('difference', 0)->count(),
            'over'             => $stockCount->items->where('difference', '>', 0)->sum('difference'),
            'under'            => abs($stockCount->items->where('difference', '<', 0)->sum('difference')),
            'unexpected_scans' => $liveUnexpected,
        ];

        // Unexpected scans list (for resolution UI)
        $unexpectedScansList = $stockCount->scans()
            ->where('is_expected', false)
            ->where('is_duplicate', false)
            ->with(['product:id,product_code,name', 'resolutionProduct:id,product_code,name', 'inventory:id,status,location_id'])
            ->orderByDesc('scanned_at')
            ->get();

        // Count unresolved missing serials
        $totalMissingSerials = 0;
        $resolvedMissingSerials = 0;

        $existingResolutions = $stockCount->serialResolutions()
            ->pluck('resolution', 'inventory_id');

        // For each item with diff < 0, count how many serials are missing and how many resolved
        $missingByProduct = [];
        foreach ($stockCount->items->where('difference', '<', 0) as $item) {
            $missingCount = abs($item->difference);
            $totalMissingSerials += $missingCount;

            // Count resolutions for this product
            $productResolved = $stockCount->serialResolutions()
                ->where('product_id', $item->product_id)
                ->count();
            $resolvedMissingSerials += min($productResolved, $missingCount);

            $missingByProduct[$item->product_id] = [
                'missing_count'  => $missingCount,
                'resolved_count' => min($productResolved, $missingCount),
            ];
        }

        $unresolvedMissing = $totalMissingSerials - $resolvedMissingSerials;
        $unresolvedUnexpected = $unexpectedScansList->whereNull('resolution')->count();

        return response()->json([
            'success' => true,
            'data'    => $stockCount,
            'stats'   => $stats,
            'unexpected_scans' => $unexpectedScansList,
            'missing_by_product' => $missingByProduct,
            'unresolved' => [
                'missing_serials'  => $unresolvedMissing,
                'unexpected_scans' => $unresolvedUnexpected,
                'total'            => $unresolvedMissing + $unresolvedUnexpected,
            ],
        ]);
    }

    /**
     * Create a new stock count (DRAFT).
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:FULL,CYCLE,SPOT',
            'note' => 'nullable|string',
            'filter_category_ids' => 'nullable|array',
            'filter_category_ids.*' => 'integer|exists:categories,id',
            'filter_location_ids' => 'nullable|array',
            'filter_location_ids.*' => 'integer|exists:locations,id',
            'filter_product_ids' => 'nullable|array',
            'filter_product_ids.*' => 'integer|exists:products,id',
        ]);

        $sc = StockCount::create([
            'code'                => StockCount::generateCode(),
            'name'                => $request->name,
            'type'                => $request->type,
            'status'              => 'DRAFT',
            'note'                => $request->note,
            'filter_category_ids' => $request->filter_category_ids,
            'filter_location_ids' => $request->filter_location_ids,
            'filter_product_ids'  => $request->filter_product_ids,
            'created_by'          => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => "สร้างรอบนับ {$sc->code} แล้ว",
            'data'    => $sc,
        ], 201);
    }

    /**
     * Start counting — snapshot current stock into items.
     */
    public function start(StockCount $stockCount): JsonResponse
    {
        if ($stockCount->status !== 'DRAFT') {
            return response()->json([
                'success' => false,
                'message' => 'สถานะต้องเป็น DRAFT เท่านั้นถึงจะเริ่มนับได้',
            ], 422);
        }

        DB::transaction(function () use ($stockCount) {
            // Build inventory query based on filters (รวมของที่อยู่ในคลังจริง)
            $query = Inventory::whereIn('status', self::COUNTABLE_STATUSES);

            if (!empty($stockCount->filter_product_ids)) {
                $query->whereIn('product_id', $stockCount->filter_product_ids);
            }
            if (!empty($stockCount->filter_category_ids)) {
                $query->whereHas('product', function ($q) use ($stockCount) {
                    $q->whereIn('category_id', $stockCount->filter_category_ids);
                });
            }
            if (!empty($stockCount->filter_location_ids)) {
                $query->whereIn('location_id', $stockCount->filter_location_ids);
            }

            // Snapshot: group by product, count
            $snapshot = $query->selectRaw('product_id, COUNT(*) as qty')
                ->groupBy('product_id')
                ->get();

            foreach ($snapshot as $row) {
                StockCountItem::create([
                    'stock_count_id' => $stockCount->id,
                    'product_id'     => $row->product_id,
                    'expected_qty'   => $row->qty,
                    'scanned_qty'    => 0,
                    'difference'     => -$row->qty,
                    'resolution'     => 'PENDING',
                ]);
            }

            $stockCount->update([
                'status'     => 'IN_PROGRESS',
                'started_at' => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'เริ่มนับสต๊อกแล้ว — พร้อมรับการสแกนจาก PDA',
            'data'    => $stockCount->fresh()->load('items.product:id,product_code,name'),
        ]);
    }

    /**
     * Resolve a missing serial individually: WRITE_OFF or KEEP.
     */
    public function resolveSerial(Request $request, StockCount $stockCount): JsonResponse
    {
        if ($stockCount->status !== 'IN_PROGRESS') {
            return response()->json(['success' => false, 'message' => 'รอบนับต้องอยู่ในสถานะ กำลังนับ'], 422);
        }

        $request->validate([
            'inventory_id' => 'required|integer|exists:inventories,id',
            'action'       => 'required|in:WRITE_OFF,KEEP',
        ]);

        $inv = Inventory::find($request->inventory_id);
        if (!$inv) {
            return response()->json(['success' => false, 'message' => 'ไม่พบ inventory'], 404);
        }

        StockCountSerialResolution::updateOrCreate(
            [
                'stock_count_id' => $stockCount->id,
                'inventory_id'   => $inv->id,
            ],
            [
                'serial_number' => $inv->serial_number,
                'product_id'    => $inv->product_id,
                'resolution'    => $request->action,
            ]
        );

        $label = $request->action === 'WRITE_OFF' ? 'ตัดสต๊อก' : 'คงไว้';
        return response()->json([
            'success' => true,
            'message' => "กำหนด {$inv->serial_number}: {$label}",
        ]);
    }

    /**
     * Resolve an unexpected scan: IMPORT or IGNORE.
     */
    public function resolveScan(Request $request, StockCount $stockCount): JsonResponse
    {
        if ($stockCount->status !== 'IN_PROGRESS') {
            return response()->json(['success' => false, 'message' => 'รอบนับต้องอยู่ในสถานะ กำลังนับ'], 422);
        }

        $rules = [
            'scan_id' => 'required|integer',
            'action'  => 'required|in:IMPORT,IGNORE',
        ];

        // If IMPORT and scan has no inventory, require product_id + location_id
        if ($request->action === 'IMPORT') {
            $rules['product_id']  = 'nullable|integer|exists:products,id';
            $rules['location_id'] = 'nullable|integer|exists:locations,id';
        }

        $request->validate($rules);

        $scan = StockCountScan::where('id', $request->scan_id)
            ->where('stock_count_id', $stockCount->id)
            ->where('is_expected', false)
            ->where('is_duplicate', false)
            ->first();

        if (!$scan) {
            return response()->json(['success' => false, 'message' => 'ไม่พบรายการสแกน'], 404);
        }

        if ($request->action === 'IMPORT' && !$scan->inventory_id) {
            // Serial not in system — need product + location for creating inventory
            if (!$request->product_id || !$request->location_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'ต้องระบุสินค้าและคลังสำหรับการนำเข้าสต๊อก',
                ], 422);
            }

            $scan->update([
                'resolution'             => 'IMPORT',
                'resolution_product_id'  => $request->product_id,
                'resolution_location_id' => $request->location_id,
            ]);
        } else {
            $scan->update([
                'resolution'             => $request->action,
                'resolution_product_id'  => null,
                'resolution_location_id' => null,
            ]);
        }

        $label = $request->action === 'IMPORT' ? 'นำเข้าสต๊อก' : 'ไม่นำเข้า';
        return response()->json([
            'success' => true,
            'message' => "กำหนดแล้ว: {$label}",
        ]);
    }

    /**
     * Complete counting — calculate differences.
     * Requires all discrepancies to be resolved first.
     */
    public function complete(StockCount $stockCount): JsonResponse
    {
        if ($stockCount->status !== 'IN_PROGRESS') {
            return response()->json([
                'success' => false,
                'message' => 'รอบนับต้องอยู่ในสถานะ กำลังนับ เท่านั้น',
            ], 422);
        }

        // ── Check all discrepancies are resolved ──
        $itemProductIds = $stockCount->items->pluck('product_id')->toArray();
        $scannedByProduct = $stockCount->scans()
            ->where('is_duplicate', false)
            ->whereIn('product_id', $itemProductIds)
            ->selectRaw('product_id, COUNT(*) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        // Check missing serials are all resolved
        foreach ($stockCount->items as $item) {
            $liveScanned = $scannedByProduct->get($item->product_id, 0);
            $liveDiff = $liveScanned - $item->expected_qty;
            if ($liveDiff < 0) {
                $missingCount = abs($liveDiff);
                $resolvedCount = $stockCount->serialResolutions()
                    ->where('product_id', $item->product_id)
                    ->count();
                if ($resolvedCount < $missingCount) {
                    $remaining = $missingCount - $resolvedCount;
                    $productName = $item->product->name ?? $item->product_id;
                    return response()->json([
                        'success'  => false,
                        'message'  => "สินค้า {$productName} ยังมี {$remaining} serial ที่ขาดยังไม่ได้กำหนด",
                    ], 422);
                }
            }
        }

        // Check unexpected scans
        $unresolvedScans = $stockCount->scans()
            ->where('is_expected', false)
            ->where('is_duplicate', false)
            ->whereNull('resolution')
            ->count();

        if ($unresolvedScans > 0) {
            return response()->json([
                'success'  => false,
                'message'  => "ยังมีรายการไม่คาดคิด {$unresolvedScans} รายการยังไม่ได้กำหนดว่าจะนำเข้าสต๊อกหรือไม่",
            ], 422);
        }

        DB::transaction(function () use ($stockCount) {
            // Recalculate scanned_qty from scans (count all non-duplicate scans with matching product)
            $itemProductIds = $stockCount->items->pluck('product_id')->toArray();
            $scannedCounts = $stockCount->scans()
                ->where('is_duplicate', false)
                ->whereIn('product_id', $itemProductIds)
                ->selectRaw('product_id, COUNT(*) as qty')
                ->groupBy('product_id')
                ->pluck('qty', 'product_id');

            foreach ($stockCount->items as $item) {
                $scanned = $scannedCounts->get($item->product_id, 0);
                $diff = $scanned - $item->expected_qty;

                // Determine resolution from serial-level data
                if ($diff === 0) {
                    $resolution = 'MATCHED';
                } elseif ($diff < 0) {
                    // All serials should be resolved, check
                    $resolvedCount = $stockCount->serialResolutions()
                        ->where('product_id', $item->product_id)
                        ->count();
                    $resolution = $resolvedCount >= abs($diff) ? 'PENDING' : 'PENDING';
                    // Keep as PENDING — will be finalized at approve()
                } else {
                    $resolution = 'PENDING';
                }

                $item->update([
                    'scanned_qty' => $scanned,
                    'difference'  => $diff,
                    'resolution'  => $resolution,
                ]);
            }

            // Handle unexpected scans (products NOT in the item list)
            $unexpectedProducts = $stockCount->scans()
                ->where('is_duplicate', false)
                ->whereNotNull('product_id')
                ->whereNotIn('product_id', $itemProductIds)
                ->selectRaw('product_id, COUNT(*) as qty')
                ->groupBy('product_id')
                ->get();

            foreach ($unexpectedProducts as $row) {
                StockCountItem::updateOrCreate(
                    ['stock_count_id' => $stockCount->id, 'product_id' => $row->product_id],
                    [
                        'expected_qty' => 0,
                        'scanned_qty'  => $row->qty,
                        'difference'   => $row->qty,
                        'resolution'   => 'PENDING',
                    ]
                );
            }

            $stockCount->update([
                'status'       => 'COMPLETED',
                'completed_at' => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'ปิดรอบนับแล้ว — ตรวจสอบผลต่างและอนุมัติปรับปรุง',
            'data'    => $stockCount->fresh()->load('items.product:id,product_code,name'),
        ]);
    }

    /**
     * Approve — apply inventory changes based on saved resolutions.
     */
    public function approve(Request $request, StockCount $stockCount): JsonResponse
    {
        if ($stockCount->status !== 'COMPLETED') {
            return response()->json([
                'success' => false,
                'message' => 'รอบนับต้องอยู่ในสถานะ เสร็จสิ้น เท่านั้น',
            ], 422);
        }

        $userId = $request->user()->id;
        $adjustedCount = 0;

        DB::transaction(function () use ($stockCount, $userId, &$adjustedCount) {
            // 1) Handle missing serials per serial-level resolution
            $writeOffResolutions = $stockCount->serialResolutions()->where('resolution', 'WRITE_OFF')->get();
            foreach ($writeOffResolutions as $res) {
                $inv = Inventory::find($res->inventory_id);
                if ($inv && in_array($inv->status, self::COUNTABLE_STATUSES)) {
                    $inv->update(['status' => 'SCRAPPED']);
                    InventoryMovement::create([
                        'inventory_id'     => $inv->id,
                        'type'             => 'ADJUSTMENT',
                        'from_location_id' => $inv->location_id,
                        'to_location_id'   => null,
                        'reference_type'   => 'stock_counts',
                        'reference_id'     => $stockCount->id,
                        'note'             => "ตรวจนับสต๊อกไม่พบ serial {$inv->serial_number} — ตัดออกจากคลัง",
                        'created_by'       => $userId,
                    ]);
                    $adjustedCount++;
                }
            }

            // Update item-level resolution based on serial resolutions
            foreach ($stockCount->items()->where('difference', '<', 0)->get() as $item) {
                $hasWriteOff = $stockCount->serialResolutions()
                    ->where('product_id', $item->product_id)
                    ->where('resolution', 'WRITE_OFF')
                    ->exists();
                $item->update(['resolution' => $hasWriteOff ? 'ADJUSTED' : 'IGNORED']);
            }

            // Items with diff >= 0 that were KEEP remain IGNORED
            $stockCount->items()->whereIn('resolution', ['KEEP', 'WRITE_OFF'])->update(['resolution' => 'IGNORED']);

            // Mark matched items as MATCHED
            $stockCount->items()->where('difference', 0)->update(['resolution' => 'MATCHED']);

            // 2) Handle IMPORT scans (create new inventory)
            $importScans = $stockCount->scans()
                ->where('is_expected', false)
                ->where('is_duplicate', false)
                ->where('resolution', 'IMPORT')
                ->get();

            foreach ($importScans as $scan) {
                if ($scan->inventory_id) {
                    // Serial exists in system but was outside scope — no action needed
                    continue;
                }

                // Serial not in system — create new inventory
                $productId  = $scan->resolution_product_id;
                $locationId = $scan->resolution_location_id;

                if (!$productId || !$locationId) continue;

                $inv = Inventory::create([
                    'serial_number'      => $scan->serial_number,
                    'product_id'         => $productId,
                    'location_id'        => $locationId,
                    'production_order_id' => null,
                    'status'             => 'IN_STOCK',
                    'condition'          => 'GOOD',
                    'note'               => 'นำเข้าจากการตรวจนับสต๊อก',
                    'received_at'        => now(),
                ]);

                InventoryMovement::create([
                    'inventory_id'    => $inv->id,
                    'type'            => 'ADJUSTMENT',
                    'from_location_id' => null,
                    'to_location_id'  => $locationId,
                    'reference_type'  => 'stock_counts',
                    'reference_id'    => $stockCount->id,
                    'note'            => 'นำเข้าสต๊อกจากการตรวจนับ',
                    'created_by'      => $userId,
                ]);

                $adjustedCount++;
            }

            $stockCount->update([
                'status'      => 'APPROVED',
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => "อนุมัติปรับปรุงสต๊อกแล้ว ({$adjustedCount} รายการ)",
            'data'    => $stockCount->fresh()->load('items.product:id,product_code,name'),
        ]);
    }

    /**
     * Cancel a stock count.
     */
    public function cancel(StockCount $stockCount): JsonResponse
    {
        if (in_array($stockCount->status, ['APPROVED', 'CANCELLED'])) {
            return response()->json([
                'success' => false,
                'message' => 'ไม่สามารถยกเลิกรอบนับนี้ได้',
            ], 422);
        }

        $stockCount->update(['status' => 'CANCELLED']);

        return response()->json([
            'success' => true,
            'message' => 'ยกเลิกรอบนับแล้ว',
        ]);
    }

    /**
     * Get scans for a stock count (paginated).
     */
    public function scans(StockCount $stockCount, Request $request): JsonResponse
    {
        $query = $stockCount->scans()
            ->with(['product:id,product_code,name']);

        if ($request->filled('filter')) {
            match ($request->filter) {
                'expected'   => $query->where('is_expected', true)->where('is_duplicate', false),
                'unexpected' => $query->where('is_expected', false),
                'duplicate'  => $query->where('is_duplicate', true),
                default      => null,
            };
        }

        $data = $query->orderByDesc('scanned_at')
            ->paginate($request->input('per_page', 30));

        return response()->json($data);
    }

    /**
     * Report data for printing stock count sheet.
     */
    public function report(StockCount $stockCount): JsonResponse
    {
        $stockCount->load([
            'creator:id,name',
            'approver:id,name',
            'items.product:id,product_code,name,category_id',
            'items.product.category:id,name',
        ]);

        // Per-product scanned counts
        $itemProductIds = $stockCount->items->pluck('product_id')->toArray();
        $scannedByProduct = $stockCount->scans()
            ->where('is_duplicate', false)
            ->whereIn('product_id', $itemProductIds)
            ->selectRaw('product_id, COUNT(*) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        // Enrich items with live scanned_qty when still IN_PROGRESS
        if ($stockCount->status === 'IN_PROGRESS') {
            foreach ($stockCount->items as $item) {
                $live = $scannedByProduct->get($item->product_id, 0);
                $item->scanned_qty = $live;
                $item->difference = $live - $item->expected_qty;
            }
        }

        // Serial resolutions grouped by product
        $serialResolutions = $stockCount->serialResolutions()
            ->with('inventory:id,serial_number')
            ->get()
            ->groupBy('product_id');

        // Build items with serial details
        $reportItems = $stockCount->items->map(function ($item) use ($serialResolutions) {
            $resolutions = $serialResolutions->get($item->product_id, collect());
            return [
                'id'           => $item->id,
                'product_code' => $item->product?->product_code,
                'product_name' => $item->product?->name,
                'category'     => $item->product?->category?->name,
                'expected_qty' => $item->expected_qty,
                'scanned_qty'  => $item->scanned_qty,
                'difference'   => $item->difference,
                'resolution'   => $item->resolution,
                'serial_resolutions' => $resolutions->map(fn ($r) => [
                    'serial_number' => $r->inventory?->serial_number ?? $r->serial_number,
                    'resolution'    => $r->resolution,
                ]),
            ];
        });

        // Unexpected scans
        $unexpectedScans = $stockCount->scans()
            ->where('is_expected', false)
            ->where('is_duplicate', false)
            ->with(['product:id,product_code,name', 'resolutionProduct:id,product_code,name'])
            ->orderByDesc('scanned_at')
            ->get()
            ->map(fn ($s) => [
                'serial_number'    => $s->serial_number,
                'product'          => $s->product?->name ?? $s->resolutionProduct?->name ?? '-',
                'product_code'     => $s->product?->product_code ?? $s->resolutionProduct?->product_code ?? '-',
                'resolution'       => $s->resolution,
                'scanned_at'       => $s->scanned_at,
            ]);

        // Stats
        $totalExpected = $stockCount->items->sum('expected_qty');
        $totalScanned  = $stockCount->status === 'IN_PROGRESS'
            ? $scannedByProduct->sum()
            : $stockCount->items->sum('scanned_qty');

        return response()->json([
            'success' => true,
            'data' => [
                'code'         => $stockCount->code,
                'name'         => $stockCount->name,
                'type'         => $stockCount->type,
                'status'       => $stockCount->status,
                'creator'      => $stockCount->creator?->name ?? '-',
                'approver'     => $stockCount->approver?->name ?? '-',
                'started_at'   => $stockCount->started_at,
                'completed_at' => $stockCount->completed_at,
                'approved_at'  => $stockCount->approved_at,
                'note'         => $stockCount->note,
                'stats' => [
                    'total_expected' => $totalExpected,
                    'total_scanned'  => $totalScanned,
                    'over'           => $stockCount->items->where('difference', '>', 0)->sum('difference'),
                    'under'          => abs($stockCount->items->where('difference', '<', 0)->sum('difference')),
                ],
                'items'            => $reportItems,
                'unexpected_scans' => $unexpectedScans,
            ],
        ]);
    }

    /**
     * Get missing serials (in system but not scanned).
     */
    public function missingSerials(StockCount $stockCount, Request $request): JsonResponse
    {
        $scannedSerials = $stockCount->scans()
            ->where('is_duplicate', false)
            ->pluck('serial_number')
            ->toArray();

        $query = Inventory::whereIn('status', self::COUNTABLE_STATUSES)
            ->whereNotIn('serial_number', $scannedSerials)
            ->with('product:id,product_code,name');

        // Apply same filters as the stock count
        if (!empty($stockCount->filter_product_ids)) {
            $query->whereIn('product_id', $stockCount->filter_product_ids);
        }
        if (!empty($stockCount->filter_category_ids)) {
            $query->whereHas('product', fn ($q) =>
                $q->whereIn('category_id', $stockCount->filter_category_ids)
            );
        }
        if (!empty($stockCount->filter_location_ids)) {
            $query->whereIn('location_id', $stockCount->filter_location_ids);
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Get serial-level resolutions for this stock count
        $resolutions = $stockCount->serialResolutions()
            ->pluck('resolution', 'inventory_id')
            ->toArray();

        $data = $query->orderBy('serial_number')
            ->paginate($request->input('per_page', 100));

        // Append resolution to each item as object { resolution: '...' }
        $data->getCollection()->transform(function ($inv) use ($resolutions) {
            $res = $resolutions[$inv->id] ?? null;
            $inv->setAttribute('serial_resolution', $res ? ['resolution' => $res] : null);
            return $inv;
        });

        return response()->json($data);
    }

    /* ══════════════════════════════════════════════════════════════
       PDA Public endpoints (token-based)
       ══════════════════════════════════════════════════════════════ */

    /**
     * Get active stock counts for PDA selection.
     */
    public function pdaActiveCounts(Request $request): JsonResponse
    {
        $token = $this->resolveToken($request);
        if (!$token) {
            return response()->json(['success' => false, 'message' => 'Token ไม่ถูกต้องหรือหมดอายุ'], 401);
        }

        $counts = StockCount::where('status', 'IN_PROGRESS')
            ->select('id', 'code', 'name', 'type', 'started_at')
            ->withCount('scans')
            ->orderByDesc('started_at')
            ->get();

        return response()->json(['success' => true, 'data' => $counts]);
    }

    /**
     * Get progress for a stock count (PDA).
     */
    public function pdaProgress(Request $request, int $id): JsonResponse
    {
        $token = $this->resolveToken($request);
        if (!$token) {
            return response()->json(['success' => false, 'message' => 'Token ไม่ถูกต้องหรือหมดอายุ'], 401);
        }

        $sc = StockCount::where('id', $id)->where('status', 'IN_PROGRESS')->first();
        if (!$sc) {
            return response()->json(['success' => false, 'message' => 'ไม่พบรอบนับที่กำลังดำเนินการ'], 404);
        }

        $totalExpected = $sc->items()->sum('expected_qty');
        $totalScanned  = $sc->scans()->where('is_duplicate', false)->count();
        $unexpected    = $sc->scans()->where('is_expected', false)->where('is_duplicate', false)->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'id'              => $sc->id,
                'code'            => $sc->code,
                'name'            => $sc->name,
                'total_expected'  => $totalExpected,
                'total_scanned'   => $totalScanned,
                'unexpected'      => $unexpected,
            ],
        ]);
    }

    /**
     * Scan a serial for stock counting (PDA, token-based).
     */
    public function pdaScan(Request $request): JsonResponse
    {
        $token = $this->resolveToken($request);
        if (!$token) {
            return response()->json(['success' => false, 'message' => 'Token ไม่ถูกต้องหรือหมดอายุ'], 401);
        }

        $request->validate([
            'stock_count_id' => 'required|integer',
            'serial_number'  => 'required|string',
        ]);

        $sc = StockCount::where('id', $request->stock_count_id)
            ->where('status', 'IN_PROGRESS')
            ->first();

        if (!$sc) {
            return response()->json([
                'success' => false,
                'message' => 'รอบนับไม่พบหรือไม่ได้อยู่ในสถานะกำลังนับ',
            ], 404);
        }

        $serial = trim($request->serial_number);

        // Extract serial number if scanner reads full label text
        if (preg_match('/([A-Z0-9]+-\d{4}-\d+)/', $serial, $matches)) {
            $serial = $matches[1];
        }

        // Check duplicate scan
        $existingScan = $sc->scans()->where('serial_number', $serial)->first();
        if ($existingScan) {
            // Log duplicate
            StockCountScan::create([
                'stock_count_id' => $sc->id,
                'serial_number'  => $serial,
                'product_id'     => $existingScan->product_id,
                'inventory_id'   => $existingScan->inventory_id,
                'pda_token_id'   => $token->id,
                'is_expected'    => $existingScan->is_expected,
                'is_duplicate'   => true,
                'scanned_at'     => now(),
            ]);

            $token->increment('scan_count');
            $token->update(['last_used_at' => now()]);

            return response()->json([
                'success' => false,
                'message' => "Serial นี้สแกนไปแล้ว (ซ้ำ)",
                'data'    => [
                    'scan_id'          => $existingScan->id,
                    'serial_number'    => $serial,
                    'product_name'     => $existingScan->product?->name,
                    'product_code'     => $existingScan->product?->product_code,
                    'inventory_status' => $existingScan->inventory?->status,
                    'status'           => 'DUPLICATE',
                ],
            ], 422);
        }

        // Look up inventory
        $inv = Inventory::where('serial_number', $serial)->first();

        if (!$inv) {
            // Serial ไม่มีในระบบเลย
            $newScan = StockCountScan::create([
                'stock_count_id' => $sc->id,
                'serial_number'  => $serial,
                'product_id'     => null,
                'inventory_id'   => null,
                'pda_token_id'   => $token->id,
                'is_expected'    => false,
                'is_duplicate'   => false,
                'scanned_at'     => now(),
            ]);

            $token->increment('scan_count');
            $token->update(['last_used_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => "⚠️ ไม่พบ Serial ในระบบ — บันทึกเป็นรายการไม่คาดคิด",
                'data'    => [
                    'scan_id'          => $newScan->id,
                    'serial_number'    => $serial,
                    'product_name'     => null,
                    'product_code'     => null,
                    'inventory_status' => null,
                    'status'           => 'UNEXPECTED',
                ],
            ]);
        }

        // Check if this serial is within the count scope
        $isInScope = $sc->items()->where('product_id', $inv->product_id)->exists();
        $isExpected = $isInScope && in_array($inv->status, self::COUNTABLE_STATUSES);

        $newScan = StockCountScan::create([
            'stock_count_id' => $sc->id,
            'serial_number'  => $serial,
            'product_id'     => $inv->product_id,
            'inventory_id'   => $inv->id,
            'pda_token_id'   => $token->id,
            'is_expected'    => $isExpected,
            'is_duplicate'   => false,
            'scanned_at'     => now(),
        ]);

        $token->increment('scan_count');
        $token->update(['last_used_at' => now()]);

        $statusLabel = $isExpected ? 'OK' : 'UNEXPECTED';
        $msg = $isExpected
            ? '✅ นับสำเร็จ'
            : '⚠️ Serial อยู่นอกขอบเขตรอบนับ — บันทึกเป็นรายการไม่คาดคิด';

        return response()->json([
            'success' => true,
            'message' => $msg,
            'data'    => [
                'scan_id'          => $newScan->id,
                'serial_number'    => $serial,
                'product_name'     => $inv->product?->name,
                'product_code'     => $inv->product?->product_code,
                'inventory_status' => $inv->status,
                'status'           => $statusLabel,
            ],
        ]);
    }

    /**
     * Get scan history for a stock count (PDA, token-based).
     */
    public function pdaScans(Request $request, int $id): JsonResponse
    {
        $token = $this->resolveToken($request);
        if (!$token) {
            return response()->json(['success' => false, 'message' => 'Token ไม่ถูกต้องหรือหมดอายุ'], 401);
        }

        $sc = StockCount::where('id', $id)->where('status', 'IN_PROGRESS')->first();
        if (!$sc) {
            return response()->json(['success' => false, 'message' => 'ไม่พบรอบนับที่กำลังดำเนินการ'], 404);
        }

        $scans = $sc->scans()
            ->where('is_duplicate', false)
            ->with(['product:id,product_code,name', 'inventory:id,status'])
            ->orderByDesc('scanned_at')
            ->limit(200)
            ->get()
            ->map(fn ($scan) => [
                'scan_id'          => $scan->id,
                'serial_number'    => $scan->serial_number,
                'product_name'     => $scan->product?->name,
                'product_code'     => $scan->product?->product_code,
                'inventory_status' => $scan->inventory?->status,
                'is_expected'      => $scan->is_expected,
                'scanned_at'       => $scan->scanned_at,
            ]);

        return response()->json([
            'success' => true,
            'data'    => $scans->values(),
        ]);
    }

    /**
     * Delete/undo a scan (PDA, token-based).
     */
    public function pdaDeleteScan(Request $request, int $scanId): JsonResponse
    {
        $token = $this->resolveToken($request);
        if (!$token) {
            return response()->json(['success' => false, 'message' => 'Token ไม่ถูกต้องหรือหมดอายุ'], 401);
        }

        $scan = StockCountScan::find($scanId);
        if (!$scan) {
            return response()->json(['success' => false, 'message' => 'ไม่พบรายการสแกน'], 404);
        }

        // Ensure the stock count is still IN_PROGRESS
        $sc = StockCount::where('id', $scan->stock_count_id)
            ->where('status', 'IN_PROGRESS')
            ->first();

        if (!$sc) {
            return response()->json([
                'success' => false,
                'message' => 'รอบนับไม่อยู่ในสถานะกำลังนับ — ไม่สามารถลบได้',
            ], 422);
        }

        $serialNumber = $scan->serial_number;

        // Also delete any duplicate scans of the same serial in this count
        $deletedCount = StockCountScan::where('stock_count_id', $sc->id)
            ->where('serial_number', $serialNumber)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => "ลบสแกน {$serialNumber} แล้ว ({$deletedCount} รายการ)",
            'data'    => ['serial_number' => $serialNumber, 'deleted_count' => $deletedCount],
        ]);
    }

    /* ══════════════════════════════════════════════════════════════
       Private helpers
       ══════════════════════════════════════════════════════════════ */

    private function handleMissingItems(StockCount $stockCount, StockCountItem $item, int $userId, ?string $note): void
    {
        $scannedSerials = $stockCount->scans()
            ->where('product_id', $item->product_id)
            ->where('is_duplicate', false)
            ->pluck('serial_number')
            ->toArray();

        $missingInventories = Inventory::whereIn('status', self::COUNTABLE_STATUSES)
            ->where('product_id', $item->product_id)
            ->whereNotIn('serial_number', $scannedSerials);

        // Apply location filter if set
        if (!empty($stockCount->filter_location_ids)) {
            $missingInventories->whereIn('location_id', $stockCount->filter_location_ids);
        }

        $missingInventories->get()->each(function (Inventory $inv) use ($userId, $note, $stockCount) {
            $inv->update(['status' => 'SCRAPPED']);

            InventoryMovement::create([
                'inventory_id'    => $inv->id,
                'type'            => 'ADJUSTMENT',
                'from_location_id' => $inv->location_id,
                'to_location_id'  => null,
                'reference_type'  => 'stock_counts',
                'reference_id'    => $stockCount->id,
                'note'            => $note ?: 'ตรวจนับสต๊อกไม่พบ — ปรับออกจากคลัง',
                'created_by'      => $userId,
            ]);
        });
    }

    /**
     * Update inventory status from PDA during stock count.
     */
    public function pdaUpdateStatus(Request $request): JsonResponse
    {
        $token = $this->resolveToken($request);
        if (!$token) {
            return response()->json(['success' => false, 'message' => 'Token ไม่ถูกต้องหรือหมดอายุ'], 401);
        }

        $request->validate([
            'scan_id' => 'required|integer',
            'status'  => 'required|string|in:IN_STOCK,DAMAGED',
        ]);

        $scan = StockCountScan::find($request->scan_id);
        if (!$scan || !$scan->inventory_id) {
            return response()->json(['success' => false, 'message' => 'ไม่พบรายการสแกนหรือไม่มีข้อมูลสินค้า'], 404);
        }

        $sc = $scan->stockCount;
        if (!$sc || $sc->status !== 'IN_PROGRESS') {
            return response()->json(['success' => false, 'message' => 'รอบนับไม่อยู่ในสถานะกำลังนับ'], 422);
        }

        $inv = Inventory::find($scan->inventory_id);
        if (!$inv) {
            return response()->json(['success' => false, 'message' => 'ไม่พบข้อมูลสินค้าในคลัง'], 404);
        }

        $oldStatus = $inv->status;
        $newStatus = $request->status;

        if ($oldStatus === $newStatus) {
            return response()->json([
                'success' => true,
                'message' => 'สถานะเดิมอยู่แล้ว',
                'data'    => ['inventory_status' => $inv->status],
            ]);
        }

        $inv->update(['status' => $newStatus]);

        // Log movement
        InventoryMovement::create([
            'inventory_id'    => $inv->id,
            'type'            => 'ADJUSTMENT',
            'from_location_id' => $inv->location_id,
            'to_location_id'  => $inv->location_id,
            'reference_type'  => 'stock_counts',
            'reference_id'    => $sc->id,
            'note'            => "ตรวจนับสต๊อก: เปลี่ยนสถานะ {$oldStatus} → {$newStatus}",
            'created_by'      => $token->created_by,
        ]);

        $statusLabels = [
            'IN_STOCK' => 'สภาพดี',
            'DAMAGED'  => 'ชำรุด',
        ];

        return response()->json([
            'success' => true,
            'message' => 'เปลี่ยนสถานะเป็น ' . ($statusLabels[$newStatus] ?? $newStatus) . ' แล้ว',
            'data'    => ['inventory_status' => $newStatus],
        ]);
    }

    private function resolveToken(Request $request): ?PdaToken
    {
        $tokenStr = $request->header('X-PDA-Token')
            ?? $request->query('token')
            ?? $request->input('token');

        if (!$tokenStr) return null;

        $token = PdaToken::where('token', $tokenStr)->first();

        return ($token && $token->isValid()) ? $token : null;
    }
}
