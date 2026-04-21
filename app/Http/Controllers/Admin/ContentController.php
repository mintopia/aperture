<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentBlock;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ContentController extends Controller
{
    public function index(): Response
    {
        $blocks = ContentBlock::orderBy('grid_row')->orderBy('grid_col')->get();

        return Inertia::render('Admin/Content/Index', [
            'blocks' => $blocks,
            'singletonTypes' => ContentBlock::SINGLETON_TYPES,
            'existingTypes' => ContentBlock::pluck('type')->unique()->values(),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Content'],
            ],
        ]);
    }

    public function editor(): Response
    {
        $blocks = ContentBlock::orderBy('grid_row')->orderBy('grid_col')->get();

        return Inertia::render('Admin/Content/Editor', [
            'blocks' => $blocks,
            'singletonTypes' => ContentBlock::SINGLETON_TYPES,
            'existingTypes' => ContentBlock::pluck('type')->unique()->values(),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Content', 'href' => route('admin.content.index')],
                ['label' => 'Grid Editor'],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => [
                'required',
                'string',
                'max:50',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (
                        in_array($value, ContentBlock::SINGLETON_TYPES, true)
                        && ContentBlock::where('type', $value)->exists()
                    ) {
                        $fail('A block of this type already exists.');
                    }
                },
            ],
            'title' => 'required|string|max:255',
            'content' => 'nullable|string',
            'is_active' => 'boolean',
            'settings' => 'nullable|array',
        ]);

        // Find first available grid position
        $position = $this->findFirstAvailablePosition();
        $validated['grid_col'] = $position['col'];
        $validated['grid_row'] = $position['row'];

        $block = ContentBlock::create($validated);

        return response()->json($block, 201);
    }

    public function update(Request $request, ContentBlock $content): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'sometimes|string|max:50',
            'title' => 'sometimes|string|max:255',
            'content' => 'nullable|string',
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

    public function updateLayout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'blocks' => 'required|array',
            'blocks.*.id' => 'required|exists:content_blocks,id',
            'blocks.*.grid_col' => 'required|integer|min:1|max:3',
            'blocks.*.grid_row' => 'required|integer|min:1',
            'blocks.*.col_span' => 'required|integer|min:1|max:3',
            'blocks.*.row_span' => 'required|integer|min:1',
        ]);

        // Validate spans don't exceed grid bounds
        foreach ($validated['blocks'] as $blockData) {
            if ($blockData['grid_col'] + $blockData['col_span'] - 1 > 3) {
                return response()->json([
                    'message' => 'Block exceeds grid width.',
                    'errors' => ['blocks' => ['A block exceeds the 3-column grid width.']],
                ], 422);
            }
        }

        // Validate no overlaps
        if ($this->hasOverlaps($validated['blocks'])) {
            return response()->json([
                'message' => 'Blocks overlap.',
                'errors' => ['blocks' => ['Two or more blocks overlap in the grid.']],
            ], 422);
        }

        DB::transaction(function () use ($validated): void {
            foreach ($validated['blocks'] as $blockData) {
                ContentBlock::where('id', $blockData['id'])->update([
                    'grid_col' => $blockData['grid_col'],
                    'grid_row' => $blockData['grid_row'],
                    'col_span' => $blockData['col_span'],
                    'row_span' => $blockData['row_span'],
                ]);
            }
        });

        return response()->json(['message' => 'Layout updated.']);
    }

    /**
     * @param  array<int, array{grid_col: int, grid_row: int, col_span: int, row_span: int}>  $blocks
     */
    private function hasOverlaps(array $blocks): bool
    {
        $occupied = [];

        foreach ($blocks as $block) {
            for ($c = $block['grid_col']; $c < $block['grid_col'] + $block['col_span']; $c++) {
                for ($r = $block['grid_row']; $r < $block['grid_row'] + $block['row_span']; $r++) {
                    $key = "{$c},{$r}";
                    if (isset($occupied[$key])) {
                        return true;
                    }
                    $occupied[$key] = true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{col: int, row: int}
     */
    private function findFirstAvailablePosition(): array
    {
        $blocks = ContentBlock::all();
        $occupied = [];

        foreach ($blocks as $block) {
            for ($c = $block->grid_col; $c < $block->grid_col + $block->col_span; $c++) {
                for ($r = $block->grid_row; $r < $block->grid_row + $block->row_span; $r++) {
                    $occupied["{$c},{$r}"] = true;
                }
            }
        }

        // Scan row by row, col by col
        for ($row = 1; $row <= 100; $row++) {
            for ($col = 1; $col <= 3; $col++) {
                if (! isset($occupied["{$col},{$row}"])) {
                    return ['col' => $col, 'row' => $row];
                }
            }
        }

        return ['col' => 1, 'row' => 1];
    }
}
