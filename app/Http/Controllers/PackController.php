<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePackRequest;
use App\Http\Requests\UpdatePackRequest;
use App\Http\Resources\PackResource;
use App\Models\Pack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PackController extends Controller
{
    /**
     * GET /api/packs
     */
    public function index(Request $request): JsonResponse
    {
        $query = Pack::with(['items.product']);

        // Filter by active status
        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        // Search by name or code
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy  = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        $perPage = $request->input('per_page', 15);
        $packs   = $query->withCount('items')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => PackResource::collection($packs),
            'meta'    => [
                'current_page' => $packs->currentPage(),
                'last_page'    => $packs->lastPage(),
                'per_page'     => $packs->perPage(),
                'total'        => $packs->total(),
            ],
        ]);
    }

    /**
     * POST /api/packs
     */
    public function store(StorePackRequest $request): JsonResponse
    {
        $pack = DB::transaction(function () use ($request) {
            $pack = Pack::create($request->only(['code', 'name', 'description', 'is_active']));

            foreach ($request->input('items', []) as $item) {
                $pack->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['quantity'],
                ]);
            }

            return $pack;
        });

        $pack->load('items.product');

        return response()->json([
            'success' => true,
            'message' => 'สร้างแพสินค้าเรียบร้อยแล้ว',
            'data'    => new PackResource($pack),
        ], 201);
    }

    /**
     * GET /api/packs/{pack}
     */
    public function show(Pack $pack): JsonResponse
    {
        $pack->load('items.product');
        $pack->loadCount('items');

        return response()->json([
            'success' => true,
            'data'    => new PackResource($pack),
        ]);
    }

    /**
     * PUT /api/packs/{pack}
     */
    public function update(UpdatePackRequest $request, Pack $pack): JsonResponse
    {
        DB::transaction(function () use ($request, $pack) {
            $pack->update($request->only(['code', 'name', 'description', 'is_active']));

            if ($request->has('items')) {
                // Delete old items and re-create
                $pack->items()->delete();

                foreach ($request->input('items', []) as $item) {
                    $pack->items()->create([
                        'product_id' => $item['product_id'],
                        'quantity'   => $item['quantity'],
                    ]);
                }
            }
        });

        $pack->load('items.product');

        return response()->json([
            'success' => true,
            'message' => 'อัปเดตแพสินค้าเรียบร้อยแล้ว',
            'data'    => new PackResource($pack),
        ]);
    }

    /**
     * DELETE /api/packs/{pack}
     */
    public function destroy(Pack $pack): JsonResponse
    {
        $pack->items()->delete();
        $pack->delete();

        return response()->json([
            'success' => true,
            'message' => 'ลบแพสินค้าเรียบร้อยแล้ว',
        ]);
    }
}
