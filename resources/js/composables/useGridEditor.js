import { computed } from 'vue';

export function useGridEditor(blocks) {
    const occupiedMap = computed(() => {
        const map = {};
        for (const block of blocks.value) {
            const col = Number(block.grid_col);
            const row = Number(block.grid_row);
            const cs = Number(block.col_span);
            const rs = Number(block.row_span);
            for (let c = col; c < col + cs; c++) {
                for (let r = row; r < row + rs; r++) {
                    map[`${c},${r}`] = block.id;
                }
            }
        }
        return map;
    });

    function isOccupied(col, row) {
        return `${col},${row}` in occupiedMap.value;
    }

    function canPlace(blockId, col, row, colSpan, rowSpan) {
        if (col < 1 || col > 3) {
            return false;
        }
        if (row < 1) {
            return false;
        }
        if (col + colSpan - 1 > 3) {
            return false;
        }

        for (let c = col; c < col + colSpan; c++) {
            for (let r = row; r < row + rowSpan; r++) {
                const key = `${c},${r}`;
                if (key in occupiedMap.value && occupiedMap.value[key] !== blockId) {
                    return false;
                }
            }
        }
        return true;
    }

    const totalRows = computed(() => {
        let max = 1;
        for (const block of blocks.value) {
            const end = Number(block.grid_row) + Number(block.row_span) - 1;
            if (end > max) {
                max = end;
            }
        }
        return max;
    });

    function moveBlock(blockId, col, row) {
        const block = blocks.value.find((b) => b.id === blockId);
        if (block) {
            block.grid_col = col;
            block.grid_row = row;
        }
    }

    function resizeBlock(blockId, colSpan, rowSpan) {
        const block = blocks.value.find((b) => b.id === blockId);
        if (block) {
            block.col_span = colSpan;
            block.row_span = rowSpan;
        }
    }

    function findFirstAvailable(colSpan = 1, rowSpan = 1) {
        for (let row = 1; row <= totalRows.value + 1; row++) {
            for (let col = 1; col <= 3; col++) {
                if (canPlace(null, col, row, colSpan, rowSpan)) {
                    return { col, row };
                }
            }
        }
        return { col: 1, row: 1 };
    }

    function getBlockAt(col, row) {
        const id = occupiedMap.value[`${col},${row}`];
        return id ? blocks.value.find((b) => b.id === id) : null;
    }

    function computeDisplacement(draggedId, targetCol, targetRow, colSpan, rowSpan) {
        const displacement = {};

        // Build a working copy of positions
        const positions = {};
        for (const block of blocks.value) {
            if (block.id === draggedId) {
                positions[block.id] = { col: targetCol, row: targetRow, colSpan, rowSpan };
            } else {
                positions[block.id] = {
                    col: Number(block.grid_col),
                    row: Number(block.grid_row),
                    colSpan: Number(block.col_span),
                    rowSpan: Number(block.row_span),
                };
            }
        }

        // Iteratively resolve overlaps
        let changed = true;
        while (changed) {
            changed = false;
            for (const block of blocks.value) {
                if (block.id === draggedId) {
                    continue;
                }
                const pos = positions[block.id];
                // Check if this block overlaps with any other block
                for (const otherId of Object.keys(positions).map(Number)) {
                    if (otherId === block.id) {
                        continue;
                    }
                    const other = positions[otherId];
                    if (
                        pos.col < other.col + other.colSpan &&
                        pos.col + pos.colSpan > other.col &&
                        pos.row < other.row + other.rowSpan &&
                        pos.row + pos.rowSpan > other.row
                    ) {
                        // Push this block below the overlapping block
                        const newRow = other.row + other.rowSpan;
                        if (newRow > pos.row) {
                            positions[block.id] = { ...pos, row: newRow };
                            displacement[block.id] = newRow;
                            changed = true;
                        }
                    }
                }
            }
        }

        return displacement;
    }

    function cellFromPointer(gridEl, clientX, clientY) {
        const rect = gridEl.getBoundingClientRect();
        const gap = parseFloat(getComputedStyle(gridEl).gap) || 12;
        const cols = 3;
        const totalGapX = gap * (cols - 1);
        const cellWidth = (rect.width - totalGapX) / cols;
        const relX = clientX - rect.left;
        const relY = clientY - rect.top;

        let col = 1;
        let accX = 0;
        for (let c = 1; c <= cols; c++) {
            const nextBound = accX + cellWidth + (c < cols ? gap : 0);
            if (relX < nextBound || c === cols) {
                col = c;
                break;
            }
            accX = nextBound;
        }

        const rows = gridEl.querySelectorAll(':scope > [style*="grid-row"]');
        let maxRow = 1;
        rows.forEach((el) => {
            const s = el.style.gridRow;
            const match = s.match(/(\d+)/);
            if (match) {
                const r = parseInt(match[1], 10);
                const spanMatch = s.match(/span\s+(\d+)/);
                const span = spanMatch ? parseInt(spanMatch[1], 10) : 1;
                maxRow = Math.max(maxRow, r + span - 1);
            }
        });

        const rowHeight = maxRow > 0 ? rect.height / maxRow : 80;
        const row = Math.max(1, Math.ceil(relY / rowHeight));

        return { col, row };
    }

    return {
        isOccupied,
        canPlace,
        totalRows,
        moveBlock,
        resizeBlock,
        findFirstAvailable,
        getBlockAt,
        occupiedMap,
        computeDisplacement,
        cellFromPointer,
    };
}
