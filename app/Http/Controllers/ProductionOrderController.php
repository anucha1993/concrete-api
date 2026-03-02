<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReceiveProductionRequest;
use App\Http\Requests\StoreProductionOrderRequest;
use App\Http\Resources\ProductionOrderResource;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Pack;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\ProductionSerial;
use App\Services\SerialNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductionOrderController extends Controller
{
    public function __construct(
        private SerialNumberService $serialService,
    ) {}

    /**
     * GET /api/production-orders
     */
    public function index(Request $request): JsonResponse
    {
        $query = ProductionOrder::with(['pack.items.product', 'items.product', 'creator'])
            ->withCount('serials');

        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('order_number', 'like', "%{$s}%");
            });
        }

        $sortBy  = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        $perPage = $request->input('per_page', 15);
        $orders  = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => ProductionOrderResource::collection($orders),
            'meta'    => [
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
            ],
        ]);
    }

    /**
     * POST /api/production-orders
     *
     * Creates a production order from a pack.
     * Auto-generates serial numbers for all items.
     */
    public function store(StoreProductionOrderRequest $request): JsonResponse
    {
        $order = DB::transaction(function () use ($request) {
            $pack = Pack::with('items.product')->findOrFail($request->pack_id);

            // Generate order number
            $orderNumber = $this->serialService->generateOrderNumber();

            $order = ProductionOrder::create([
                'order_number' => $orderNumber,
                'pack_id'      => $pack->id,
                'quantity'     => $request->quantity,
                'status'       => 'DRAFT',
                'note'         => $request->note,
                'created_by'   => auth()->id(),
            ]);

            // Expand pack items × quantity into production_order_items
            foreach ($pack->items as $packItem) {
                $plannedQty = $packItem->quantity * $request->quantity;

                $orderItem = $order->items()->create([
                    'product_id'  => $packItem->product_id,
                    'planned_qty' => $plannedQty,
                    'good_qty'    => 0,
                    'damaged_qty' => 0,
                ]);

                // Generate serials for each planned unit
                $product = $packItem->product;
                $serials = $this->serialService->generate($product->category_id, $plannedQty);

                $locationId = $request->location_id ?? $product->default_location_id;

                foreach ($serials as $serialNumber) {
                    $inventory = Inventory::create([
                        'serial_number'       => $serialNumber,
                        'product_id'          => $product->id,
                        'location_id'         => $locationId,
                        'production_order_id' => $order->id,
                        'status'              => 'PENDING', // pending until verified/received
                        'condition'           => 'GOOD',
                        'received_at'         => null,
                        'last_movement_at'    => null,
                    ]);

                    ProductionSerial::create([
                        'production_order_id'      => $order->id,
                        'production_order_item_id' => $orderItem->id,
                        'inventory_id'             => $inventory->id,
                        'condition'                => 'GOOD',
                    ]);
                }
            }

            return $order;
        });

        $order->load(['pack.items.product', 'items.product', 'creator']);
        $order->loadCount('serials');

        return response()->json([
            'success' => true,
            'message' => 'สร้างใบสั่งผลิตเรียบร้อยแล้ว',
            'data'    => new ProductionOrderResource($order),
        ], 201);
    }

    /**
     * GET /api/production-orders/{id}
     */
    public function show(ProductionOrder $productionOrder): JsonResponse
    {
        $productionOrder->load([
            'pack.items.product',
            'items.product',
            'creator',
            'confirmer',
            'serials.inventory',
        ]);
        $productionOrder->loadCount('serials');

        return response()->json([
            'success' => true,
            'data'    => new ProductionOrderResource($productionOrder),
        ]);
    }

    /**
     * POST /api/production-orders/{id}/confirm
     * Confirms the order (status → CONFIRMED)
     */
    public function confirm(ProductionOrder $productionOrder): JsonResponse
    {
        if ($productionOrder->status !== 'DRAFT') {
            return response()->json([
                'success' => false,
                'message' => 'ใบสั่งผลิตนี้ไม่อยู่ในสถานะ DRAFT',
            ], 422);
        }

        $productionOrder->update([
            'status'       => 'CONFIRMED',
            'confirmed_by' => auth()->id(),
            'confirmed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'ยืนยันใบสั่งผลิตเรียบร้อยแล้ว',
            'data'    => new ProductionOrderResource($productionOrder->fresh(['pack', 'items.product', 'creator', 'confirmer'])),
        ]);
    }

    /**
     * POST /api/production-orders/{id}/start
     * Start production (status → IN_PROGRESS)
     */
    public function start(ProductionOrder $productionOrder): JsonResponse
    {
        if ($productionOrder->status !== 'CONFIRMED') {
            return response()->json([
                'success' => false,
                'message' => 'ใบสั่งผลิตนี้ต้องอยู่ในสถานะ CONFIRMED ก่อน',
            ], 422);
        }

        $productionOrder->update(['status' => 'IN_PROGRESS']);

        return response()->json([
            'success' => true,
            'message' => 'เริ่มผลิตเรียบร้อยแล้ว',
            'data'    => new ProductionOrderResource($productionOrder->fresh(['pack', 'items.product', 'creator'])),
        ]);
    }

    /**
     * POST /api/production-orders/{id}/receive
     *
     * Receive produced items: mark damaged items and complete the order.
     * Good qty is auto-calculated from verified labels (barcode scans).
     * User only inputs damaged_qty. Validates good + damaged = planned.
     */
    public function receive(ReceiveProductionRequest $request, ProductionOrder $productionOrder): JsonResponse
    {
        if ($productionOrder->status !== 'IN_PROGRESS') {
            return response()->json([
                'success' => false,
                'message' => 'ใบสั่งผลิตต้องอยู่ในสถานะ "กำลังผลิต" ถึงจะรับเข้าคลังได้',
            ], 422);
        }

        // Count verified serials per order item (this is good_qty)
        $productionOrder->load('items');
        $errors = [];

        // Calculate good_qty from verified labels for each item
        $verifiedCounts = [];
        foreach ($productionOrder->items as $orderItem) {
            $verifiedCounts[$orderItem->id] = ProductionSerial::where('production_order_item_id', $orderItem->id)
                ->whereHas('inventory', fn ($q) => $q->whereNotNull('label_verified_at')->where('status', 'PENDING'))
                ->count();
        }

        foreach ($request->items as $itemData) {
            $orderItem = $productionOrder->items->firstWhere('id', $itemData['production_order_item_id']);
            if (!$orderItem) continue;

            $verifiedGoodQty = $verifiedCounts[$orderItem->id] ?? 0;
            $damagedQty = $itemData['damaged_qty'];

            if (($verifiedGoodQty + $damagedQty) !== $orderItem->planned_qty) {
                $productName = $orderItem->product?->name ?? 'ID:' . $orderItem->product_id;
                $errors[] = "{$productName}: ของดี ({$verifiedGoodQty}) + ชำรุด ({$damagedQty}) ≠ แผน ({$orderItem->planned_qty})";
            }
        }

        if (!empty($errors)) {
            return response()->json([
                'success' => false,
                'message' => 'ยอดของดี + ชำรุดไม่ตรงกับจำนวนแผนผลิต',
                'errors'  => $errors,
            ], 422);
        }

        DB::transaction(function () use ($request, $productionOrder, $verifiedCounts) {
            $now = now();
            $userId = auth()->id();

            foreach ($request->items as $itemData) {
                $orderItem = ProductionOrderItem::findOrFail($itemData['production_order_item_id']);
                if ($orderItem->production_order_id !== $productionOrder->id) continue;

                $damagedQty = $itemData['damaged_qty'];
                $goodQty = $verifiedCounts[$orderItem->id] ?? 0;

                // Update order item counts
                $orderItem->update([
                    'good_qty'    => $goodQty,
                    'damaged_qty' => $damagedQty,
                ]);

                // === Move verified serials to IN_STOCK ===
                $goodSerials = ProductionSerial::where('production_order_item_id', $orderItem->id)
                    ->whereHas('inventory', fn ($q) => $q->whereNotNull('label_verified_at')->where('status', 'PENDING'))
                    ->get();

                foreach ($goodSerials as $serial) {
                    $serial->update(['condition' => 'GOOD']);
                    $serial->inventory->update([
                        'status'           => 'IN_STOCK',
                        'condition'        => 'GOOD',
                        'received_at'      => $now,
                        'last_movement_at' => $now,
                    ]);

                    InventoryMovement::create([
                        'inventory_id'     => $serial->inventory_id,
                        'type'             => 'PRODUCTION_IN',
                        'from_location_id' => null,
                        'to_location_id'   => $serial->inventory->location_id,
                        'reference_type'   => 'production_orders',
                        'reference_id'     => $productionOrder->id,
                        'note'             => $request->note ?? 'รับเข้าคลัง',
                        'created_by'       => $userId,
                    ]);
                }

                // === Mark unverified serials as DAMAGED ===
                if ($damagedQty > 0) {
                    $damagedSerials = ProductionSerial::where('production_order_item_id', $orderItem->id)
                        ->whereHas('inventory', fn ($q) => $q->where('status', 'PENDING'))
                        ->limit($damagedQty)
                        ->get();

                    foreach ($damagedSerials as $serial) {
                        $serial->update(['condition' => 'DAMAGED']);
                        $serial->inventory->update([
                            'status'           => 'DAMAGED',
                            'condition'        => 'DAMAGED',
                            'received_at'      => $now,
                            'last_movement_at' => $now,
                        ]);

                        InventoryMovement::create([
                            'inventory_id'     => $serial->inventory_id,
                            'type'             => 'PRODUCTION_IN',
                            'from_location_id' => null,
                            'to_location_id'   => $serial->inventory->location_id,
                            'reference_type'   => 'production_orders',
                            'reference_id'     => $productionOrder->id,
                            'note'             => ($request->note ?? '') . ' (ชำรุด)',
                            'created_by'       => $userId,
                        ]);
                    }
                }
            }

            // Mark order as COMPLETED
            $productionOrder->update([
                'status'       => 'COMPLETED',
                'completed_at' => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'รับสินค้าเข้าคลังเรียบร้อยแล้ว — ใบสั่งผลิตเสร็จสิ้น',
            'data'    => new ProductionOrderResource($productionOrder->fresh(['pack', 'items.product', 'creator'])),
        ]);
    }

    /**
     * POST /api/production-orders/{id}/cancel
     */
    public function cancel(ProductionOrder $productionOrder): JsonResponse
    {
        if (in_array($productionOrder->status, ['COMPLETED', 'CANCELLED'])) {
            return response()->json([
                'success' => false,
                'message' => 'ใบสั่งผลิตนี้ไม่สามารถยกเลิกได้',
            ], 422);
        }

        DB::transaction(function () use ($productionOrder) {
            // Remove pending inventory items
            Inventory::where('production_order_id', $productionOrder->id)
                ->where('status', 'PENDING')
                ->forceDelete();

            $productionOrder->update(['status' => 'CANCELLED']);
        });

        return response()->json([
            'success' => true,
            'message' => 'ยกเลิกใบสั่งผลิตเรียบร้อยแล้ว',
        ]);
    }

    /**
     * GET /api/production-orders/{id}/serials
     * List all serials for a production order
     */
    public function serials(ProductionOrder $productionOrder): JsonResponse
    {
        $serials = $productionOrder->serials()
            ->with(['inventory.product', 'inventory.location', 'orderItem.product'])
            ->get()
            ->map(function ($s) {
                return [
                    'id'            => $s->id,
                    'serial_number' => $s->inventory->serial_number,
                    'product'       => [
                        'id'           => $s->inventory->product->id,
                        'product_code' => $s->inventory->product->product_code,
                        'name'         => $s->inventory->product->name,
                    ],
                    'location'      => $s->inventory->location ? [
                        'id'   => $s->inventory->location->id,
                        'name' => $s->inventory->location->name,
                    ] : null,
                    'condition'     => $s->condition,
                    'status'        => $s->inventory->status,
                    'received_at'   => $s->inventory->received_at?->toISOString(),
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => $serials,
        ]);
    }
}
