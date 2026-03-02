<?php

namespace App\Http\Controllers;

use App\Http\Resources\LocationResource;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    /**
     * GET /api/locations
     */
    public function index(Request $request): JsonResponse
    {
        $query = Location::query();

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        $locations = $query->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data'    => LocationResource::collection($locations),
        ]);
    }

    /**
     * POST /api/locations
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'code'        => ['required', 'string', 'max:50', 'unique:locations,code'],
            'description' => ['nullable', 'string'],
            'is_active'   => ['sometimes', 'boolean'],
        ]);

        $location = Location::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'สร้างตำแหน่งคลังสำเร็จ',
            'data'    => new LocationResource($location),
        ], 201);
    }

    /**
     * GET /api/locations/{location}
     */
    public function show(Location $location): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new LocationResource($location),
        ]);
    }

    /**
     * PUT /api/locations/{location}
     */
    public function update(Request $request, Location $location): JsonResponse
    {
        $validated = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'code'        => ['sometimes', 'string', 'max:50', 'unique:locations,code,' . $location->id],
            'description' => ['nullable', 'string'],
            'is_active'   => ['sometimes', 'boolean'],
        ]);

        $location->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'อัปเดตตำแหน่งคลังสำเร็จ',
            'data'    => new LocationResource($location),
        ]);
    }

    /**
     * DELETE /api/locations/{location}
     */
    public function destroy(Location $location): JsonResponse
    {
        if ($location->products()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'ไม่สามารถลบตำแหน่งที่มีสินค้าอ้างอิงได้',
            ], 422);
        }

        $location->delete();

        return response()->json([
            'success' => true,
            'message' => 'ลบตำแหน่งคลังสำเร็จ',
        ]);
    }
}
