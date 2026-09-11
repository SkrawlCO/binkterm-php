import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';
const here=(p:string)=>fileURLToPath(new URL(p,import.meta.url));
export default defineConfig({base:'./',plugins:[react()],resolve:{alias:[
{find:/^@\/engine(.*)$/,replacement:here('../upstream/src/engine')+'$1'},
{find:/^@\/lib\/(clock|result)$/,replacement:here('../upstream/src/lib')+'/$1'},
{find:'@',replacement:here('./src')}
]}});
