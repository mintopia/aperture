import { describe, it, expect } from 'vitest';
import { ref } from 'vue';
import { useGridEditor } from '@/composables/useGridEditor.js';

describe('useGridEditor', () => {
    function makeBlocks(positions) {
        return ref(
            positions.map((p, i) => ({
                id: i + 1,
                type: 'event_info',
                title: `Block ${i + 1}`,
                grid_col: p[0],
                grid_row: p[1],
                col_span: p[2] ?? 1,
                row_span: p[3] ?? 1,
            })),
        );
    }

    it('computes occupied cells from blocks', () => {
        const blocks = makeBlocks([
            [1, 1],
            [2, 1],
        ]);
        const { isOccupied } = useGridEditor(blocks);
        expect(isOccupied(1, 1)).toBe(true);
        expect(isOccupied(2, 1)).toBe(true);
        expect(isOccupied(3, 1)).toBe(false);
    });

    it('accounts for col_span and row_span in occupied cells', () => {
        const blocks = makeBlocks([[1, 1, 2, 2]]);
        const { isOccupied } = useGridEditor(blocks);
        expect(isOccupied(1, 1)).toBe(true);
        expect(isOccupied(2, 1)).toBe(true);
        expect(isOccupied(1, 2)).toBe(true);
        expect(isOccupied(2, 2)).toBe(true);
        expect(isOccupied(3, 1)).toBe(false);
    });

    it('detects if a move would cause overlap', () => {
        const blocks = makeBlocks([
            [1, 1],
            [2, 1],
        ]);
        const { canPlace } = useGridEditor(blocks);
        expect(canPlace(1, 2, 1, 1, 1)).toBe(false);
        expect(canPlace(1, 3, 1, 1, 1)).toBe(true);
    });

    it('allows placing a block in its own current position', () => {
        const blocks = makeBlocks([[1, 1]]);
        const { canPlace } = useGridEditor(blocks);
        expect(canPlace(1, 1, 1, 1, 1)).toBe(true);
    });

    it('rejects placement outside grid columns (1-3)', () => {
        const blocks = makeBlocks([]);
        const { canPlace } = useGridEditor(blocks);
        expect(canPlace(null, 0, 1, 1, 1)).toBe(false);
        expect(canPlace(null, 4, 1, 1, 1)).toBe(false);
    });

    it('rejects col_span that exceeds grid width', () => {
        const blocks = makeBlocks([]);
        const { canPlace } = useGridEditor(blocks);
        expect(canPlace(null, 2, 1, 3, 1)).toBe(false);
        expect(canPlace(null, 1, 1, 3, 1)).toBe(true);
    });

    it('computes total rows needed', () => {
        const blocks = makeBlocks([
            [1, 1],
            [1, 3, 1, 2],
        ]);
        const { totalRows } = useGridEditor(blocks);
        expect(totalRows.value).toBe(4);
    });

    it('moveBlock updates block position', () => {
        const blocks = makeBlocks([[1, 1]]);
        const { moveBlock } = useGridEditor(blocks);
        moveBlock(1, 3, 2);
        expect(blocks.value[0].grid_col).toBe(3);
        expect(blocks.value[0].grid_row).toBe(2);
    });

    it('resizeBlock updates block span', () => {
        const blocks = makeBlocks([[1, 1]]);
        const { resizeBlock } = useGridEditor(blocks);
        resizeBlock(1, 2, 3);
        expect(blocks.value[0].col_span).toBe(2);
        expect(blocks.value[0].row_span).toBe(3);
    });

    it('findFirstAvailable returns an empty cell', () => {
        const blocks = makeBlocks([
            [1, 1],
            [2, 1],
            [3, 1],
        ]);
        const { findFirstAvailable } = useGridEditor(blocks);
        const pos = findFirstAvailable();
        expect(pos).toEqual({ col: 1, row: 2 });
    });
});
