<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\ProductionOrder;
use App\Models\StockDeduction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    /* ─────────────────────────────────────────────────────────────
     *  1) รายงานสต๊อก (Inventory Report)
     * ───────────────────────────────────────────────────────────── */
    public function inventory(Request $request): JsonResponse
    {
        $query = Inventory::with(['product.category', 'location'])
            ->select('inventories.*');

        // Filters
        if ($request->filled('status'))      $query->where('status', $request->status);
        if ($request->filled('condition'))    $query->where('condition', $request->condition);
        if ($request->filled('product_id'))   $query->where('product_id', $request->product_id);
        if ($request->filled('location_id'))  $query->where('location_id', $request->location_id);
        if ($request->filled('category_id'))  $query->whereHas('product', fn($q) => $q->where('category_id', $request->category_id));
        if ($request->filled('date_from'))    $query->whereDate('received_at', '>=', $request->date_from);
        if ($request->filled('date_to'))      $query->whereDate('received_at', '<=', $request->date_to);
        if ($request->filled('search'))       $query->where('serial_number', 'like', "%{$request->search}%");

        $query->orderBy('received_at', 'desc');

        // Summary stats
        $summaryQuery = (clone $query)->toBase();
        $summary = [
            'total'      => (clone $query)->count(),
            'in_stock'   => (clone $query)->where('status', 'IN_STOCK')->count(),
            'sold'       => (clone $query)->where('status', 'SOLD')->count(),
            'damaged'    => (clone $query)->where('status', 'DAMAGED')->count(),
            'scrapped'   => (clone $query)->where('status', 'SCRAPPED')->count(),
            'pending'    => (clone $query)->where('status', 'PENDING')->count(),
        ];

        $paginated = $query->paginate($request->get('per_page', 50));

        return response()->json([
            'success' => true,
            'summary' => $summary,
            'data'    => $paginated->items(),
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ]);
    }

    /* ─────────────────────────────────────────────────────────────
     *  2) รายงานการตัดสต๊อก (Stock Deductions Report)
     * ───────────────────────────────────────────────────────────── */
    public function stockDeductions(Request $request): JsonResponse
    {
        $query = StockDeduction::with(['creator:id,name', 'approver:id,name', 'lines.product:id,product_code,name']);

        if ($request->filled('status'))   $query->where('status', $request->status);
        if ($request->filled('type'))     $query->where('type', $request->type);
        if ($request->filled('search'))   $query->where(function ($q) use ($request) {
            $q->where('code', 'like', "%{$request->search}%")
              ->orWhere('customer_name', 'like', "%{$request->search}%")
              ->orWhere('reference_doc', 'like', "%{$request->search}%");
        });
        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->whereDate('created_at', '<=', $request->date_to);

        $query->orderBy('created_at', 'desc');

        // Summary
        $base = (clone $query);
        $summary = [
            'total'     => (clone $base)->count(),
            'completed' => (clone $base)->where('status', 'COMPLETED')->count(),
            'approved'  => (clone $base)->where('status', 'APPROVED')->count(),
            'pending'   => (clone $base)->where('status', 'PENDING')->count(),
            'draft'     => (clone $base)->where('status', 'DRAFT')->count(),
            'total_items' => (clone $base)->withCount('lines')->get()->sum('lines_count'),
        ];

        $paginated = $query->paginate($request->get('per_page', 50));

        return response()->json([
            'success' => true,
            'summary' => $summary,
            'data'    => $paginated->items(),
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ]);
    }

    /* ─────────────────────────────────────────────────────────────
     *  3) รายงานเคลมสินค้า (Claims Report)
     * ───────────────────────────────────────────────────────────── */
    public function claims(Request $request): JsonResponse
    {
        $query = Claim::with(['creator:id,name', 'approver:id,name', 'lines.product:id,product_code,name']);

        if ($request->filled('status'))     $query->where('status', $request->status);
        if ($request->filled('type'))       $query->where('type', $request->type);
        if ($request->filled('resolution')) $query->where('resolution', $request->resolution);
        if ($request->filled('search'))     $query->where(function ($q) use ($request) {
            $q->where('code', 'like', "%{$request->search}%")
              ->orWhere('customer_name', 'like', "%{$request->search}%");
        });
        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->whereDate('created_at', '<=', $request->date_to);

        $query->orderBy('created_at', 'desc');

        $base = (clone $query);
        $summary = [
            'total'     => (clone $base)->count(),
            'approved'  => (clone $base)->where('status', 'APPROVED')->count(),
            'pending'   => (clone $base)->where('status', 'PENDING')->count(),
            'rejected'  => (clone $base)->where('status', 'REJECTED')->count(),
            'draft'     => (clone $base)->where('status', 'DRAFT')->count(),
            'total_items' => (clone $base)->withCount('lines')->get()->sum('lines_count'),
        ];

        $paginated = $query->paginate($request->get('per_page', 50));

        return response()->json([
            'success' => true,
            'summary' => $summary,
            'data'    => $paginated->items(),
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ]);
    }

    /* ─────────────────────────────────────────────────────────────
     *  4) รายงานการผลิต (Production Report)
     * ───────────────────────────────────────────────────────────── */
    public function production(Request $request): JsonResponse
    {
        $query = ProductionOrder::with(['pack:id,code,name', 'creator:id,name', 'items.product:id,product_code,name']);

        if ($request->filled('status'))   $query->where('status', $request->status);
        if ($request->filled('search'))   $query->where(function ($q) use ($request) {
            $q->where('order_number', 'like', "%{$request->search}%");
        });
        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->whereDate('created_at', '<=', $request->date_to);

        $query->orderBy('created_at', 'desc');

        $base = (clone $query);
        $summary = [
            'total'        => (clone $base)->count(),
            'completed'    => (clone $base)->where('status', 'COMPLETED')->count(),
            'in_progress'  => (clone $base)->where('status', 'IN_PROGRESS')->count(),
            'confirmed'    => (clone $base)->where('status', 'CONFIRMED')->count(),
            'draft'        => (clone $base)->where('status', 'DRAFT')->count(),
            'total_qty'    => (clone $base)->sum('quantity'),
        ];

        $paginated = $query->paginate($request->get('per_page', 50));

        return response()->json([
            'success' => true,
            'summary' => $summary,
            'data'    => $paginated->items(),
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ]);
    }

    /* ─────────────────────────────────────────────────────────────
     *  5) รายงานความเคลื่อนไหว (Inventory Movements Report)
     * ───────────────────────────────────────────────────────────── */
    public function movements(Request $request): JsonResponse
    {
        $query = InventoryMovement::with([
            'inventory:id,serial_number,product_id',
            'inventory.product:id,product_code,name',
            'fromLocation:id,name',
            'toLocation:id,name',
            'creator:id,name',
        ]);

        if ($request->filled('type'))        $query->where('type', $request->type);
        if ($request->filled('location_id')) {
            $locId = $request->location_id;
            $query->where(fn($q) => $q->where('from_location_id', $locId)->orWhere('to_location_id', $locId));
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('inventory', fn($q) => $q->where('serial_number', 'like', "%{$search}%"));
        }
        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->whereDate('created_at', '<=', $request->date_to);

        $query->orderBy('created_at', 'desc');

        $base = (clone $query);
        $types = DB::table('inventory_movements')
            ->select('type', DB::raw('count(*) as count'))
            ->when($request->filled('date_from'), fn($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        $summary = [
            'total'           => (clone $base)->count(),
            'production_in'   => $types['PRODUCTION_IN'] ?? 0,
            'transfer'        => $types['TRANSFER'] ?? 0,
            'sold'            => $types['SOLD'] ?? 0,
            'damaged'         => $types['DAMAGED'] ?? 0,
            'adjustment'      => $types['ADJUSTMENT'] ?? 0,
            'claim_return'    => $types['CLAIM_RETURN'] ?? 0,
            'scrap'           => $types['SCRAP'] ?? 0,
        ];

        $paginated = $query->paginate($request->get('per_page', 50));

        return response()->json([
            'success' => true,
            'summary' => $summary,
            'data'    => $paginated->items(),
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════
     *  EXCEL EXPORT ENDPOINTS
     * ═══════════════════════════════════════════════════════════════ */

    public function exportInventory(Request $request)
    {
        $query = Inventory::with(['product.category', 'location']);

        if ($request->filled('status'))      $query->where('status', $request->status);
        if ($request->filled('condition'))    $query->where('condition', $request->condition);
        if ($request->filled('product_id'))   $query->where('product_id', $request->product_id);
        if ($request->filled('location_id'))  $query->where('location_id', $request->location_id);
        if ($request->filled('category_id'))  $query->whereHas('product', fn($q) => $q->where('category_id', $request->category_id));
        if ($request->filled('date_from'))    $query->whereDate('received_at', '>=', $request->date_from);
        if ($request->filled('date_to'))      $query->whereDate('received_at', '<=', $request->date_to);
        if ($request->filled('search'))       $query->where('serial_number', 'like', "%{$request->search}%");

        $query->orderBy('received_at', 'desc');

        $items = $query->limit(10000)->get();

        $rows = [['Serial Number', 'รหัสสินค้า', 'ชื่อสินค้า', 'หมวดหมู่', 'คลัง', 'สถานะ', 'สภาพ', 'หมายเหตุ', 'วันที่รับเข้า']];
        $statusMap  = ['PENDING' => 'รอรับเข้า', 'IN_STOCK' => 'ในคลัง', 'SOLD' => 'ขายแล้ว', 'DAMAGED' => 'ชำรุด', 'SCRAPPED' => 'ทำลาย'];
        $condMap    = ['GOOD' => 'สภาพดี', 'DAMAGED' => 'ชำรุด'];

        foreach ($items as $item) {
            $rows[] = [
                $item->serial_number,
                $item->product?->product_code ?? '',
                $item->product?->name ?? '',
                $item->product?->category?->name ?? '',
                $item->location?->name ?? '',
                $statusMap[$item->status] ?? $item->status,
                $condMap[$item->condition] ?? $item->condition,
                $item->note ?? '',
                $item->received_at?->format('d/m/Y H:i') ?? '',
            ];
        }

        return $this->downloadExcel($rows, 'inventory_report');
    }

    public function exportStockDeductions(Request $request)
    {
        $query = StockDeduction::with(['creator:id,name', 'approver:id,name', 'lines.product:id,product_code,name']);

        if ($request->filled('status'))    $query->where('status', $request->status);
        if ($request->filled('type'))      $query->where('type', $request->type);
        if ($request->filled('search'))    $query->where(function ($q) use ($request) {
            $q->where('code', 'like', "%{$request->search}%")
              ->orWhere('customer_name', 'like', "%{$request->search}%");
        });
        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->whereDate('created_at', '<=', $request->date_to);

        $query->orderBy('created_at', 'desc');

        $items = $query->limit(5000)->get();

        $typeMap   = ['SOLD' => 'ขาย', 'LOST' => 'สูญหาย', 'DAMAGED' => 'ชำรุด', 'OTHER' => 'อื่นๆ'];
        $statusMap = ['DRAFT' => 'แบบร่าง', 'PENDING' => 'รอดำเนินการ', 'IN_PROGRESS' => 'กำลังดำเนินการ', 'COMPLETED' => 'เสร็จสิ้น', 'APPROVED' => 'อนุมัติ', 'CANCELLED' => 'ยกเลิก'];

        $rows = [['รหัส', 'ประเภท', 'สถานะ', 'ลูกค้า', 'เอกสารอ้างอิง', 'รายการสินค้า', 'จำนวนรวม', 'เหตุผล', 'ผู้สร้าง', 'ผู้อนุมัติ', 'วันที่สร้าง']];

        foreach ($items as $item) {
            $products = $item->lines->map(fn($l) => ($l->product?->product_code ?? '') . ' x' . $l->quantity)->implode(', ');
            $totalQty = $item->lines->sum('quantity');
            $rows[] = [
                $item->code,
                $typeMap[$item->type] ?? $item->type,
                $statusMap[$item->status] ?? $item->status,
                $item->customer_name ?? '',
                $item->reference_doc ?? '',
                $products,
                $totalQty,
                $item->reason ?? '',
                $item->creator?->name ?? '',
                $item->approver?->name ?? '',
                $item->created_at?->format('d/m/Y H:i') ?? '',
            ];
        }

        return $this->downloadExcel($rows, 'stock_deductions_report');
    }

    public function exportClaims(Request $request)
    {
        $query = Claim::with(['creator:id,name', 'approver:id,name', 'lines.product:id,product_code,name']);

        if ($request->filled('status'))     $query->where('status', $request->status);
        if ($request->filled('type'))       $query->where('type', $request->type);
        if ($request->filled('resolution')) $query->where('resolution', $request->resolution);
        if ($request->filled('search'))     $query->where(function ($q) use ($request) {
            $q->where('code', 'like', "%{$request->search}%")
              ->orWhere('customer_name', 'like', "%{$request->search}%");
        });
        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->whereDate('created_at', '<=', $request->date_to);

        $query->orderBy('created_at', 'desc');

        $items = $query->limit(5000)->get();

        $typeMap   = ['RETURN' => 'คืนสินค้า', 'TRANSPORT_DAMAGE' => 'เสียหายจากขนส่ง', 'DEFECT' => 'สินค้าชำรุด', 'WRONG_SPEC' => 'สเปคไม่ตรง', 'OTHER' => 'อื่นๆ'];
        $statusMap = ['DRAFT' => 'แบบร่าง', 'PENDING' => 'รอดำเนินการ', 'APPROVED' => 'อนุมัติ', 'REJECTED' => 'ปฏิเสธ', 'CANCELLED' => 'ยกเลิก'];
        $resMap    = ['RETURN_STOCK' => 'คืนสต็อก', 'RETURN_DAMAGED' => 'คืนเป็นชำรุด', 'REPLACE' => 'เปลี่ยนสินค้า', 'REFUND' => 'คืนเงิน', 'CREDIT_NOTE' => 'ลดหนี้'];

        $rows = [['รหัส', 'ประเภท', 'สถานะ', 'การจัดการ', 'ลูกค้า', 'รายการสินค้า', 'จำนวนรวม', 'เหตุผล', 'ผู้สร้าง', 'ผู้อนุมัติ', 'วันที่สร้าง']];

        foreach ($items as $item) {
            $products = $item->lines->map(fn($l) => ($l->product?->product_code ?? '') . ' x' . $l->quantity)->implode(', ');
            $totalQty = $item->lines->sum('quantity');
            $rows[] = [
                $item->code,
                $typeMap[$item->type] ?? $item->type,
                $statusMap[$item->status] ?? $item->status,
                $resMap[$item->resolution] ?? ($item->resolution ?? '-'),
                $item->customer_name ?? '',
                $products,
                $totalQty,
                $item->reason ?? '',
                $item->creator?->name ?? '',
                $item->approver?->name ?? '',
                $item->created_at?->format('d/m/Y H:i') ?? '',
            ];
        }

        return $this->downloadExcel($rows, 'claims_report');
    }

    public function exportProduction(Request $request)
    {
        $query = ProductionOrder::with(['pack:id,code,name', 'creator:id,name', 'items.product:id,product_code,name']);

        if ($request->filled('status'))    $query->where('status', $request->status);
        if ($request->filled('search'))    $query->where('order_number', 'like', "%{$request->search}%");
        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->whereDate('created_at', '<=', $request->date_to);

        $query->orderBy('created_at', 'desc');

        $items = $query->limit(5000)->get();

        $statusMap = ['DRAFT' => 'แบบร่าง', 'CONFIRMED' => 'ยืนยัน', 'IN_PROGRESS' => 'กำลังผลิต', 'COMPLETED' => 'เสร็จสิ้น', 'CANCELLED' => 'ยกเลิก'];

        $rows = [['เลขที่ใบสั่ง', 'แพ', 'จำนวนชุด', 'สถานะ', 'รายการสินค้า', 'ของดี', 'ชำรุด', 'ผู้สร้าง', 'วันที่สร้าง', 'วันที่เสร็จ']];

        foreach ($items as $item) {
            $products = $item->items->map(fn($i) => ($i->product?->product_code ?? '') . ' x' . $i->planned_qty)->implode(', ');
            $goodQty   = $item->items->sum('good_qty');
            $dmgQty    = $item->items->sum('damaged_qty');
            $rows[] = [
                $item->order_number,
                $item->pack?->name ?? '-',
                $item->quantity,
                $statusMap[$item->status] ?? $item->status,
                $products,
                $goodQty,
                $dmgQty,
                $item->creator?->name ?? '',
                $item->created_at?->format('d/m/Y H:i') ?? '',
                $item->completed_at ? $item->completed_at->format('d/m/Y H:i') : '-',
            ];
        }

        return $this->downloadExcel($rows, 'production_report');
    }

    public function exportMovements(Request $request)
    {
        $query = InventoryMovement::with([
            'inventory:id,serial_number,product_id',
            'inventory.product:id,product_code,name',
            'fromLocation:id,name',
            'toLocation:id,name',
            'creator:id,name',
        ]);

        if ($request->filled('type'))        $query->where('type', $request->type);
        if ($request->filled('location_id')) {
            $locId = $request->location_id;
            $query->where(fn($q) => $q->where('from_location_id', $locId)->orWhere('to_location_id', $locId));
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('inventory', fn($q) => $q->where('serial_number', 'like', "%{$search}%"));
        }
        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->whereDate('created_at', '<=', $request->date_to);

        $query->orderBy('created_at', 'desc');

        $items = $query->limit(10000)->get();

        $typeMap = [
            'PRODUCTION_IN' => 'ผลิตเข้าคลัง', 'TRANSFER' => 'ย้ายคลัง', 'SOLD' => 'ขาย',
            'DAMAGED' => 'ชำรุด', 'ADJUSTMENT' => 'ปรับสต็อก', 'SCRAP' => 'ทำลาย', 'CLAIM_RETURN' => 'คืนสต็อกจากเคลม',
        ];

        $rows = [['วันที่', 'Serial Number', 'รหัสสินค้า', 'ชื่อสินค้า', 'ประเภท', 'จากคลัง', 'ไปคลัง', 'หมายเหตุ', 'ผู้ดำเนินการ']];

        foreach ($items as $item) {
            $rows[] = [
                $item->created_at?->format('d/m/Y H:i') ?? '',
                $item->inventory?->serial_number ?? '',
                $item->inventory?->product?->product_code ?? '',
                $item->inventory?->product?->name ?? '',
                $typeMap[$item->type] ?? $item->type,
                $item->fromLocation?->name ?? '-',
                $item->toLocation?->name ?? '-',
                $item->note ?? '',
                $item->creator?->name ?? '',
            ];
        }

        return $this->downloadExcel($rows, 'movements_report');
    }

    /* ── Helper: Array to Excel download ───────────────────────── */
    private function downloadExcel(array $rows, string $filename)
    {
        $export = new \App\Exports\ArrayExport($rows);
        $fileName = $filename . '_' . now()->format('Ymd_His') . '.xlsx';
        return Excel::download($export, $fileName);
    }
}
