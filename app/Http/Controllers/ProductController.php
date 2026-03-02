<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * GET /api/products
     *
     * Supports: pagination, filter by category, filter by size_type, search
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::with(['category', 'defaultLocation']);

        // Filter by category
        if ($request->filled('category_id')) {
            $query->byCategory($request->category_id);
        }

        // Filter by size_type
        if ($request->filled('size_type')) {
            $query->bySizeType($request->size_type);
        }

        // Filter by active status
        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        // Search by name, product_code, or barcode
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('product_code', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        // Optionally append stock & reserved counts
        if (filter_var($request->input('with_stock'), FILTER_VALIDATE_BOOLEAN)) {
            $query->withCount([
                'inventories as stock_count' => function ($q) {
                    $q->where('status', 'IN_STOCK');
                },
            ]);

            // reserved_count = sum ของ quantity จาก lines ในใบตัดสต๊อกที่ยังไม่อนุมัติ/ยกเลิก (รวม DRAFT)
            $query->addSelect([
                'reserved_count' => \App\Models\StockDeductionLine::selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('stock_deduction_lines.product_id', 'products.id')
                    ->whereIn('stock_deduction_id', function ($sub) {
                        $sub->select('id')
                            ->from('stock_deductions')
                            ->whereNotIn('status', ['APPROVED', 'CANCELLED'])
                            ->whereNull('deleted_at');
                    }),
            ]);
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        // Pagination
        $perPage = $request->input('per_page', 15);
        $products = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => ProductResource::collection($products),
            'meta'    => [
                'current_page' => $products->currentPage(),
                'last_page'    => $products->lastPage(),
                'per_page'     => $products->perPage(),
                'total'        => $products->total(),
            ],
        ]);
    }

    /**
     * POST /api/products
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());
        $product->load(['category', 'defaultLocation']);

        return response()->json([
            'success' => true,
            'message' => 'สร้างสินค้าสำเร็จ',
            'data'    => new ProductResource($product),
        ], 201);
    }

    /**
     * GET /api/products/{product}
     */
    public function show(Product $product): JsonResponse
    {
        $product->load(['category', 'defaultLocation']);

        return response()->json([
            'success' => true,
            'data'    => new ProductResource($product),
        ]);
    }

    /**
     * PUT /api/products/{product}
     */
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product->update($request->validated());
        $product->load(['category', 'defaultLocation']);

        return response()->json([
            'success' => true,
            'message' => 'อัปเดตสินค้าสำเร็จ',
            'data'    => new ProductResource($product),
        ]);
    }

    /**
     * DELETE /api/products/{product}
     */
    public function destroy(Product $product): JsonResponse
    {
        $product->delete(); // Soft delete

        return response()->json([
            'success' => true,
            'message' => 'ลบสินค้าสำเร็จ',
        ]);
    }
}
