<?php

namespace App\Http\Controllers;

use App\Http\Resources\InventoryResource;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockDeductionLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    /**
     * GET /api/inventory
     * List all inventory items (individual serials)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Inventory::with(['product.category', 'location', 'latestClaimReturn', 'latestAdjustment.creator']);

        if ($request->filled('product_id')) {
            $query->byProduct($request->product_id);
        }

        if ($request->filled('location_id')) {
            $query->byLocation($request->location_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('condition')) {
            $query->where('condition', $request->condition);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('serial_number', 'like', "%{$s}%")
                  ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', "%{$s}%")
                      ->orWhere('product_code', 'like', "%{$s}%"));
            });
        }

        $sortBy  = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        $perPage = $request->input('per_page', 15);
        $items   = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => InventoryResource::collection($items),
            'meta'    => [
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
                'per_page'     => $items->perPage(),
                'total'        => $items->total(),
            ],
        ]);
    }

    /**
     * GET /api/inventory/summary
     * Stock summary per product
     */
    public function summary(Request $request): JsonResponse
    {
        // Exclude PENDING items — they haven't been received into warehouse yet
        $query = Inventory::select(
            'product_id',
            DB::raw("SUM(CASE WHEN status = 'IN_STOCK' THEN 1 ELSE 0 END) as in_stock_count"),
            DB::raw("SUM(CASE WHEN status = 'DAMAGED' THEN 1 ELSE 0 END) as damaged_count"),
            DB::raw("SUM(CASE WHEN status = 'SOLD' THEN 1 ELSE 0 END) as sold_count"),
            DB::raw("SUM(CASE WHEN status != 'PENDING' THEN 1 ELSE 0 END) as total_count"),
        )->where('status', '!=', 'PENDING')
          ->groupBy('product_id');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->whereHas('product', fn ($q) => $q->where('name', 'like', "%{$s}%")
                ->orWhere('product_code', 'like', "%{$s}%"));
        }

        $summaries = $query->get();

        // คำนวณจำนวนจองต่อสินค้า (SUM quantity จาก lines ของใบตัดสต๊อกที่ยังไม่อนุมัติ/ยกเลิก)
        $reservedMap = StockDeductionLine::select('product_id', DB::raw('SUM(quantity) as reserved'))
            ->whereHas('stockDeduction', fn ($q) => $q->whereNotIn('status', ['APPROVED', 'CANCELLED']))
            ->groupBy('product_id')
            ->pluck('reserved', 'product_id');

        // Eager load products
        $productIds = $summaries->pluck('product_id');
        $products   = Product::with('category')->whereIn('id', $productIds)->get()->keyBy('id');

        $result = $summaries->map(function ($s) use ($products, $reservedMap) {
            $product = $products[$s->product_id] ?? null;
            return [
                'product_id'     => $s->product_id,
                'product_code'   => $product?->product_code,
                'product_name'   => $product?->name,
                'category_name'  => $product?->category?->name,
                'stock_min'      => $product?->stock_min ?? 0,
                'stock_max'      => $product?->stock_max ?? 0,
                'in_stock_count' => (int) $s->in_stock_count,
                'damaged_count'  => (int) $s->damaged_count,
                'sold_count'     => (int) $s->sold_count,
                'total_count'    => (int) $s->total_count,
                'reserved_count' => (int) ($reservedMap[$s->product_id] ?? 0),
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $result,
        ]);
    }

    /**
     * GET /api/inventory/{inventory}
     */
    public function show(Inventory $inventory): JsonResponse
    {
        $inventory->load(['product.category', 'location', 'productionOrder', 'movements.creator', 'movements.fromLocation', 'movements.toLocation', 'latestClaimReturn', 'latestAdjustment.creator']);

        return response()->json([
            'success' => true,
            'data'    => new InventoryResource($inventory),
        ]);
    }

    /**
     * PUT /api/inventory/{inventory}
     * Admin adjustment — update status, condition, location, note
     */
    public function update(Request $request, Inventory $inventory): JsonResponse
    {
        $request->validate([
            'status'      => 'sometimes|in:PENDING,IN_STOCK,SOLD,DAMAGED,SCRAPPED',
            'condition'   => 'sometimes|in:GOOD,DAMAGED',
            'location_id' => 'sometimes|nullable|exists:locations,id',
            'note'        => 'sometimes|nullable|string|max:500',
            'reason'      => 'required|string|max:500',
        ]);

        $userId = $request->user()->id;
        $changes = [];
        $oldLocationId = $inventory->location_id;

        return DB::transaction(function () use ($request, $inventory, $userId, &$changes, $oldLocationId) {
            if ($request->has('status') && $request->status !== $inventory->status) {
                $changes[] = "สถานะ: {$inventory->status} → {$request->status}";
                $inventory->status = $request->status;
            }

            if ($request->has('condition') && $request->condition !== $inventory->condition) {
                $changes[] = "สภาพ: {$inventory->condition} → {$request->condition}";
                $inventory->condition = $request->condition;
            }

            if ($request->has('location_id') && $request->location_id != $inventory->location_id) {
                $oldLoc = $inventory->location?->name ?? '-';
                $inventory->location_id = $request->location_id;
                $newLoc = $inventory->location_id
                    ? (\App\Models\Location::find($inventory->location_id)?->name ?? '-')
                    : '-';
                $changes[] = "คลัง: {$oldLoc} → {$newLoc}";
            }

            if ($request->has('note')) {
                $inventory->note = $request->note;
            }

            if (empty($changes)) {
                return response()->json([
                    'success' => false,
                    'message' => 'ไม่มีการเปลี่ยนแปลง',
                ], 422);
            }

            $inventory->last_movement_at = now();
            $inventory->save();

            // Create ADJUSTMENT movement for audit trail
            InventoryMovement::create([
                'inventory_id'     => $inventory->id,
                'type'             => 'ADJUSTMENT',
                'from_location_id' => $oldLocationId,
                'to_location_id'   => $inventory->location_id,
                'reference_type'   => 'admin_adjustment',
                'reference_id'     => $inventory->id,
                'note'             => 'ปรับปรุงข้อมูล (Admin) — '
                    . implode(', ', $changes)
                    . ' | เหตุผล: ' . $request->reason,
                'created_by'       => $userId,
            ]);

            $inventory->load(['product.category', 'location', 'productionOrder', 'movements.creator', 'movements.fromLocation', 'movements.toLocation', 'latestClaimReturn', 'latestAdjustment.creator']);

            return response()->json([
                'success' => true,
                'message' => 'ปรับปรุงข้อมูลสำเร็จ — ' . implode(', ', $changes),
                'data'    => new InventoryResource($inventory),
            ]);
        });
    }

    /**
     * GET /api/inventory/alerts
     * Returns alerts: low stock, no movement, long storage
     */
    public function alerts(Request $request): JsonResponse
    {
        $inactiveDays  = (int) $request->input('inactive_days', 30);
        $longStoreDays = (int) $request->input('long_store_days', 90);

        // ── 1) Low stock (below stock_min) ───────────────
        $lowStock = DB::table('products')
            ->select(
                'products.id as product_id',
                'products.product_code',
                'products.name as product_name',
                'products.stock_min',
                DB::raw("COALESCE(inv.cnt, 0) as current_stock"),
            )
            ->leftJoinSub(
                Inventory::select('product_id', DB::raw("COUNT(*) as cnt"))
                    ->where('status', 'IN_STOCK')
                    ->groupBy('product_id'),
                'inv',
                'products.id',
                '=',
                'inv.product_id'
            )
            ->where('products.is_active', true)
            ->where('products.stock_min', '>', 0)
            ->whereRaw('COALESCE(inv.cnt, 0) < products.stock_min')
            ->orderByRaw('COALESCE(inv.cnt, 0) ASC')
            ->get();

        // ── 2) No movement (สินค้าไม่เคลื่อนไหว) ─────────
        $noMovement = Inventory::with('product:id,product_code,name', 'location:id,name')
            ->where('status', 'IN_STOCK')
            ->where(function ($q) use ($inactiveDays) {
                $q->where('last_movement_at', '<', now()->subDays($inactiveDays))
                  ->orWhereNull('last_movement_at');
            })
            ->select('id', 'serial_number', 'product_id', 'location_id', 'last_movement_at')
            ->orderBy('last_movement_at')
            ->limit(100)
            ->get();

        // ── 3) Long storage (ค้างคลังนาน) ────────────────
        $longStorage = Inventory::with('product:id,product_code,name', 'location:id,name')
            ->where('status', 'IN_STOCK')
            ->where('received_at', '<', now()->subDays($longStoreDays))
            ->select('id', 'serial_number', 'product_id', 'location_id', 'received_at')
            ->orderBy('received_at')
            ->limit(100)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'low_stock'    => $lowStock,
                'no_movement'  => $noMovement,
                'long_storage' => $longStorage,
            ],
        ]);
    }
}
