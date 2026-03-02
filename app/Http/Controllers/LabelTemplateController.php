<?php

namespace App\Http\Controllers;

use App\Models\LabelTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LabelTemplateController extends Controller
{
    /**
     * GET /label-templates
     */
    public function index(Request $request): JsonResponse
    {
        $query = LabelTemplate::with(['creator:id,name', 'updater:id,name']);

        if ($request->boolean('active_only', false)) {
            $query->where('is_active', true);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $templates = $query->orderByDesc('is_default')->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data'    => $templates,
        ]);
    }

    /**
     * GET /label-templates/{id}
     */
    public function show(LabelTemplate $labelTemplate): JsonResponse
    {
        $labelTemplate->load(['creator:id,name', 'updater:id,name']);

        return response()->json([
            'success' => true,
            'data'    => $labelTemplate,
        ]);
    }

    /**
     * POST /label-templates
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'paper_width'   => 'required|string|max:10',
            'paper_height'  => 'required|string|max:10',
            'template_json' => 'required|array',
            'is_default'    => 'boolean',
        ]);

        // If setting as default, un-default others
        if (!empty($validated['is_default'])) {
            LabelTemplate::where('is_default', true)->update(['is_default' => false]);
        }

        $template = LabelTemplate::create([
            ...$validated,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $template->load(['creator:id,name', 'updater:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'สร้าง Template สำเร็จ',
            'data'    => $template,
        ], 201);
    }

    /**
     * PUT /label-templates/{id}
     */
    public function update(Request $request, LabelTemplate $labelTemplate): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'sometimes|required|string|max:255',
            'paper_width'   => 'sometimes|required|string|max:10',
            'paper_height'  => 'sometimes|required|string|max:10',
            'template_json' => 'sometimes|required|array',
            'is_default'    => 'boolean',
            'is_active'     => 'boolean',
        ]);

        // If setting as default, un-default others
        if (!empty($validated['is_default'])) {
            LabelTemplate::where('id', '!=', $labelTemplate->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $labelTemplate->update([
            ...$validated,
            'updated_by' => auth()->id(),
        ]);

        $labelTemplate->load(['creator:id,name', 'updater:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'อัปเดต Template สำเร็จ',
            'data'    => $labelTemplate,
        ]);
    }

    /**
     * DELETE /label-templates/{id}
     */
    public function destroy(LabelTemplate $labelTemplate): JsonResponse
    {
        $labelTemplate->delete();

        return response()->json([
            'success' => true,
            'message' => 'ลบ Template สำเร็จ',
        ]);
    }
}
