import { describe, it, expect } from 'vitest';
import { ref } from 'vue';
import { useGridEditor } from '@/composables/useGridEditor.js';

describe('useGridEditor', () => {
    function makeBlocks(positions) {
        return ref(
            positions.map((p, i) => ({
                id: i + 1,
                type: 'custom_markdown',
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

    it('resizeBlock produces valid numbers when col_span and row_span are strings from MySQL', () => {
        // MySQL returns integers as strings; if col_span is "1" (string),
        // arithmetic like "1" + Math.round(dx) produces string concatenation ("1-1") -> NaN
        const blocks = ref([
            {
                id: 1,
                type: 'custom_markdown',
                title: 'Block 1',
                grid_col: 1,
                grid_row: 1,
                col_span: '2', // string from MySQL
                row_span: '1', // string from MySQL
            },
        ]);
        const { resizeBlock, canPlace } = useGridEditor(blocks);

        // Resize to smaller: colSpan from "2" to 1
        resizeBlock(1, 1, 1);
        expect(blocks.value[0].col_span).toBe(1);
        expect(blocks.value[0].row_span).toBe(1);
        expect(Number.isNaN(blocks.value[0].col_span)).toBe(false);
        expect(Number.isNaN(blocks.value[0].row_span)).toBe(false);

        // Verify canPlace still works with the updated values
        expect(canPlace(1, 1, 1, 1, 1)).toBe(true);
    });

    it('isOccupied handles string col_span and row_span from MySQL', () => {
        // When MySQL returns col_span/row_span as strings, the occupied cell
        // computation must still work correctly. The bug: "1" + "2" = "12" (string concat)
        // causing the loop to run from 1 to 12 instead of 1 to 3.
        const blocks = ref([
            {
                id: 1,
                type: 'custom_markdown',
                title: 'Block 1',
                grid_col: 1,
                grid_row: 1,
                col_span: '2', // string from MySQL
                row_span: '2', // string from MySQL
            },
        ]);
        const { isOccupied } = useGridEditor(blocks);

        // With col_span=2 and row_span=2, cells (1,1), (2,1), (1,2), (2,2) should be occupied
        expect(isOccupied(1, 1)).toBe(true);
        expect(isOccupied(2, 1)).toBe(true);
        expect(isOccupied(1, 2)).toBe(true);
        expect(isOccupied(2, 2)).toBe(true);
        // Cell (3,1) should NOT be occupied — but with string concatenation bug it would be
        expect(isOccupied(3, 1)).toBe(false);
    });

    it('totalRows computes correctly when col_span and row_span are strings', () => {
        // MySQL returns strings for integers; totalRows must still compute correctly
        const blocks = ref([
            {
                id: 1,
                type: 'custom_markdown',
                title: 'Block 1',
                grid_col: 1,
                grid_row: 1,
                col_span: '1', // string from MySQL
                row_span: '2', // string from MySQL
            },
        ]);
        const { totalRows } = useGridEditor(blocks);
        // grid_row(1) + row_span(2) - 1 = 2, so totalRows should be 2
        // With string concat: "1" + "2" - 1 = "12" - 1 = 11 (wrong)
        expect(totalRows.value).toBe(2);
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

    describe('computeDisplacement', () => {
        it('returns empty map when target is unoccupied', () => {
            const blocks = makeBlocks([
                [1, 1],
                [3, 1],
            ]);
            const { computeDisplacement } = useGridEditor(blocks);
            const result = computeDisplacement(1, 2, 1, 1, 1);
            expect(result).toEqual({});
        });

        it('pushes overlapped block down', () => {
            const blocks = makeBlocks([
                [1, 1],
                [2, 1],
            ]);
            const { computeDisplacement } = useGridEditor(blocks);
            // Move block 1 to col 2 row 1 — overlaps block 2
            const result = computeDisplacement(1, 2, 1, 1, 1);
            expect(result[2]).toBe(2); // block 2 pushed to row 2
        });

        it('cascades displacement when pushed block overlaps another', () => {
            const blocks = makeBlocks([
                [1, 1],
                [2, 1],
                [2, 2],
            ]);
            const { computeDisplacement } = useGridEditor(blocks);
            // Move block 1 to col 2 row 1 — pushes block 2 to row 2, which pushes block 3 to row 3
            const result = computeDisplacement(1, 2, 1, 1, 1);
            expect(result[2]).toBe(2);
            expect(result[3]).toBe(3);
        });

        it('handles multi-span block displacement', () => {
            const blocks = makeBlocks([
                [1, 1, 2, 1],
                [1, 2],
            ]);
            const { computeDisplacement } = useGridEditor(blocks);
            // Move block 1 (2x1) to row 2 — overlaps block 2 at (1,2)
            const result = computeDisplacement(1, 1, 2, 2, 1);
            expect(result[2]).toBe(3); // block 2 pushed to row 3
        });

        it('does not displace blocks that are not overlapped', () => {
            const blocks = makeBlocks([
                [1, 1],
                [3, 3],
            ]);
            const { computeDisplacement } = useGridEditor(blocks);
            const result = computeDisplacement(1, 1, 2, 1, 1);
            expect(result).toEqual({}); // block 2 at (3,3) is not affected
        });
    });
});
