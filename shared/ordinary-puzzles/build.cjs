// Mechanical TS emit only: pinned source is never rewritten. No React/DOM build.
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');
const root = __dirname;
fs.mkdirSync(path.join(root, 'runtime'), { recursive: true });
for (const [source, output] of [['op-board/store.ts', 'board.cjs'], ['op-puzzle-pack/index.ts', 'pack.cjs']]) {
    const result = ts.transpileModule(fs.readFileSync(path.join(root, 'upstream/src', source), 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020, esModuleInterop: true },
        fileName: source,
    });
    // The pack remains a single, byte-exact JSON input at its provenance path.
    const code = result.outputText.replace('require("./puzzle-pack.json")', 'require("../upstream/src/op-puzzle-pack/puzzle-pack.json")');
    if (code.includes('require("react-native")')) throw new Error('Unexpected native runtime import');
    fs.writeFileSync(path.join(root, 'runtime', output), code);
}
