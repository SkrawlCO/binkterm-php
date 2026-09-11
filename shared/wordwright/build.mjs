// tsc owns compilation. Only resolve emitted extensionless/alias imports for native ESM.
import { readdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
async function walk(dir) {
    for (const entry of await readdir(dir, { withFileTypes: true })) {
        const file = path.join(dir, entry.name);
        if (entry.isDirectory()) await walk(file);
        else if (file.endsWith('.js') || file.endsWith('.d.ts')) {
            const source = await readFile(file, 'utf8');
            const result = source.replace(/(from\s*|import\s*\(|import\s*)(['"])(@\/[^'"]+|\.\.?\/[^'"]+)\2/g, (match, prefix, quote, specifier) => {
                if (specifier.startsWith('@/')) {
                    specifier = path.relative(path.dirname(file), path.join('dist/upstream/src', specifier.slice(2))).split(path.sep).join('/');
                    if (!specifier.startsWith('.')) specifier = './' + specifier;
                }
                return prefix + quote + specifier + (specifier.endsWith('.js') ? '' : '.js') + quote;
            });
            await writeFile(file, result);
        }
    }
}
await walk('dist');
