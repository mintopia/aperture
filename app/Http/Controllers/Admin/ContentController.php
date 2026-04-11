<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContentController extends Controller
{
    public function index(): Response
    {
        $blocks = ContentBlock::orderBy('sort_order')->get();

        return Inertia::render('Admin/Content/Index', [
            'blocks' => $blocks,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|max:50',
            'title' => 'required|string|max:255',
            'content' => 'nullable|string',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'settings' => 'nullable|array',
        ]);

        $block = ContentBlock::create($validated);

        return response()->json($block, 201);
    }

    public function update(Request $request, ContentBlock $content): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'sometimes|string|max:50',
            'title' => 'sometimes|string|max:255',
            'content' => 'nullable|string',
            'sort_order' => 'sometimes|integer',
            'is_active' => 'sometimes|boolean',
            'settings' => 'nullable|array',
        ]);

        $content->update($validated);

        return response()->json($content);
    }

    public function destroy(ContentBlock $content): JsonResponse
    {
        $content->delete();

        return response()->json(null, 204);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'blocks' => 'required|array',
            'blocks.*.id' => 'required|exists:content_blocks,id',
            'blocks.*.sort_order' => 'required|integer',
        ]);

        foreach ($validated['blocks'] as $blockData) {
            ContentBlock::where('id', $blockData['id'])->update(['sort_order' => $blockData['sort_order']]);
        }

        return response()->json(['message' => 'Order updated.']);
    }
}
