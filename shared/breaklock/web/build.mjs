import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
const here = path.dirname(fileURLToPath(import.meta.url));
const require = createRequire(process.env.BREAKLOCK_BUILD_DEPS
    ? path.resolve(process.env.BREAKLOCK_BUILD_DEPS, 'package.json') : import.meta.url);
const esbuild = require('esbuild'), sass = require('sass'), YAML = require('yaml');
const out = path.resolve(process.argv[2] || '');
const production = path.resolve(here, '../../../public_html/webdoors/breaklock/assets');
if (!process.argv[2] || (!out.startsWith('/tmp/') && out !== production)) throw new Error('Use /tmp output or the BreakLock production assets directory');
await fs.mkdir(out, { recursive: true });
const dict = YAML.parse(await fs.readFile(path.join(here, 'upstream/src/l10n/en.yml'), 'utf8'));
const translate = text => text.replace(/#@[a-z_0-9]+/g, token => dict[token.slice(2)] ?? token);
await esbuild.build({
    absWorkingDir: here, entryPoints: ['app.js'], bundle: true, outfile: path.join(out, 'app.js'),
    format: 'iife', target: 'es2022', metafile: true,
    plugins: [{ name: 'upstream-presentation', setup(build) {
        build.onResolve({ filter: /models\/pattern$/ }, () => ({ path: path.resolve(here, '../upstream/pattern.js') }));
        build.onLoad({ filter: /\.scss$/ }, async args => ({
            contents: sass.compile(args.path, { logger: sass.Logger.silent }).css
                .replace(/@font-face\s*\{[^}]*\}/g, ''), loader: 'css', resolveDir: path.dirname(args.path),
        }));
    } }],
}).then(result => fs.writeFile(path.join(out, 'build-inputs.json'), JSON.stringify(result.metafile, null, 2)));
// Apply the same post-bundle token replacement as upstream's build.
const js = await fs.readFile(path.join(out, 'app.js'), 'utf8');
await fs.writeFile(path.join(out, 'app.js'), translate(js));
let html = await fs.readFile(path.join(here, 'upstream/index.html'), 'utf8');
// No upstream SW, browser storage easter egg, locale redirects or install manifest.
html = html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '')
    .replace(/<base[^>]*>/, '<base href="./">')
    .replace(/<link\b[^>]*>/g, '')
    .replace('</head>', '<link rel="stylesheet" href="app.css"></head>')
    .replace('</body>', '<script src="app.js"></script></body>');
await fs.writeFile(path.join(out, 'index.html'), translate(html));
await fs.mkdir(path.join(out, 'assets'), { recursive: true });
await fs.copyFile(path.join(here, 'upstream/assets/intro.svg'), path.join(out, 'assets/intro.svg'));
await fs.copyFile(path.join(here, '../LICENSE.upstream'), path.join(out, 'LICENSE.upstream'));
console.log(`Built local-only BreakLock surface: ${out}`);
