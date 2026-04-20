import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

function getVueFiles(dir) {
    const results = [];

    function walk(currentDir) {
        const entries = readdirSync(currentDir);
        for (const entry of entries) {
            const fullPath = join(currentDir, entry);
            const stat = statSync(fullPath);
            if (stat.isDirectory()) {
                walk(fullPath);
            } else if (entry.endsWith('.vue')) {
                results.push(fullPath);
            }
        }
    }

    walk(dir);
    return results;
}

describe('Design Consistency', () => {
    it('no .vue files use raw oklch values in status dot glows', () => {
        const resourcesDir = join(__dirname, '../../resources/js');
        const vueFiles = getVueFiles(resourcesDir);
        const pattern = /shadow-\[0_0_6px_oklch\(/g;
        const violations = [];

        for (const file of vueFiles) {
            const content = readFileSync(file, 'utf-8');
            const matches = content.match(pattern);
            if (matches) {
                violations.push({
                    file: file.replace(join(__dirname, '../../'), ''),
                    count: matches.length,
                });
            }
        }

        expect(violations, `Found raw oklch glow values in: ${JSON.stringify(violations, null, 2)}`).toHaveLength(0);
    });
});
