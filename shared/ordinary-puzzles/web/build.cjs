const path=require('node:path'),fs=require('node:fs'),{execFileSync}=require('node:child_process');
if(process.env.ALLOW_FONT_FALLBACK!=='1') throw Error('Build requires ALLOW_FONT_FALLBACK=1 (Inter only)');
execFileSync(process.execPath,['build.cjs'],{cwd:path.resolve(__dirname,'..'),stdio:'inherit'});
require('esbuild').buildSync({entryPoints:[path.join(__dirname,'app.jsx')],bundle:true,outfile:path.join(__dirname,'dist/app.js'),define:{'process.env.NODE_ENV':'"production"'},alias:{'react-native':path.join(__dirname,'compat.jsx'),'mobx-react-lite':path.join(__dirname,'compat.jsx'),'op-design':path.join(__dirname,'compat.jsx'),'op-common':path.join(__dirname,'compat.jsx')},minify:true});
for(const f of ['index.html','style.css'])fs.copyFileSync(path.join(__dirname,f),path.join(__dirname,'dist',f));
fs.cpSync(path.join(__dirname,'assets'),path.join(__dirname,'dist/assets'),{recursive:true});
fs.copyFileSync(path.join(__dirname,'../LICENSE.upstream'),path.join(__dirname,'dist/LICENSE.upstream'));
console.log('Local Inter-only Web build: web/dist');

if(process.argv.includes('--production')) {
 const target=path.resolve(__dirname,'../../../public_html/webdoors/ordinary-puzzles/assets');
 fs.cpSync(path.join(__dirname,'dist'),target,{recursive:true});
 console.log('Packaged '+target);
}
