<?php

namespace App\Http\Controllers;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * GET /api/categories
     */
    public function index(Request $request): JsonResponse
    {
        $query = Category::query();

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        $categories = $query->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data'    => CategoryResource::collection($categories),
        ]);
    }

    /**
     * POST /api/categories
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'        => ['nullable', 'string', 'max:50', 'unique:categories,code'],
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active'   => ['sometimes', 'boolean'],
        ]);

        $validated['slug'] = Str::slug($validated['name']) ?: Str::slug($validated['code'] ?? '') ?: Str::lower(str_replace(' ', '-', $validated['name']));

        $category = Category::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'สร้างหมวดหมู่สำเร็จ',
            'data'    => new CategoryResource($category),
        ], 201);
    }

    /**
     * GET /api/categories/{category}
     */
    public function show(Category $category): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new CategoryResource($category),
        ]);
    }

    /**
     * PUT /api/categories/{category}
     */
    public function update(Request $request, Category $category): JsonResponse
    {
        $validated = $request->validate([
            'code'        => ['nullable', 'string', 'max:50', 'unique:categories,code,' . $category->id],
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active'   => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']) ?: Str::slug($validated['code'] ?? $category->code ?? '') ?: Str::lower(str_replace(' ', '-', $validated['name']));
        }

        $category->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'อัปเดตหมวดหมู่สำเร็จ',
            'data'    => new CategoryResource($category),
        ]);
    }

    /**
     * DELETE /api/categories/{category}
     */
    public function destroy(Category $category): JsonResponse
    {
        if ($category->products()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'ไม่สามารถลบหมวดหมู่ที่มีสินค้าได้',
            ], 422);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'ลบหมวดหมู่สำเร็จ',
        ]);
    }
}
