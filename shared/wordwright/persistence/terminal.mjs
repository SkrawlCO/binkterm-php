import { LeasedClient } from '../dist/persistence/client.js';
import { TerminalAdapter } from '../terminal/adapter.mjs';
export async function connectTerminal(request,{onError=()=>{},intervalMs=10000}={}) {
    let adapter;
    const lease=new LeasedClient(request,()=>{if(adapter&&lease.error){adapter.message=lease.error;onError(Error(lease.error));}},intervalMs);
    await lease.acquire();adapter=new TerminalAdapter(lease.session);
    const handle=adapter.handle.bind(adapter);
    adapter.handle=(text,key={})=>{
        if(!lease.writable){if(key.ctrl&&key.name==='c')return 'quit';return;}
        return lease.mutate(()=>handle(text,key));
    };
    return {adapter,lease};
}
