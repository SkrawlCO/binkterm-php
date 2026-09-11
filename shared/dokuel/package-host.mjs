// Reproducible production assets. Run shared + Web builds first.
import {cp, mkdir, readdir, unlink} from 'node:fs/promises';
const target=new URL('../../public_html/webdoors/dokuel/assets/',import.meta.url);
await mkdir(target,{recursive:true});
// Clean only prior build assets in this specifically owned generated directory.
const assets=new URL('assets/',target);await mkdir(assets,{recursive:true});
for(const name of await readdir(assets))if(/^(index|dm-sans)-[\w.-]+\.(js|css|woff2)$/.test(name))await unlink(new URL(name,assets));
await cp(new URL('./web/dist/',import.meta.url),target,{recursive:true});
