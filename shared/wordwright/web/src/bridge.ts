import { LeasedClient, webTransport } from '../../persistence/client';
import { WordwrightSession, type Snapshot } from '../../session';
import { useSyncExternalStore } from 'react';
let session: WordwrightSession;
let current: Snapshot;
const listeners = new Set<() => void>();
let restoring = false;
let lease:LeasedClient|undefined;
export const storageStatus=()=>({connected:!!lease,active:lease?.writable??true,error:lease?.error??'',revision:lease?.envelope?.revision});
function publish() { current=session.snapshot(); for(const listener of listeners) listener(); }
export async function initialize() { session=await WordwrightSession.create(); publish(); if(window.wordwrightHost)await review.connect(window.wordwrightHost.endpoint,window.wordwrightHost.csrfToken); }
export function act<T>(action:(s:WordwrightSession)=>T):T {
    if(restoring) throw new Error('Restore in progress');
    if(lease)return lease.mutate(action);
    const result=action(session); publish(); return result;
}
export const subscribe=(listener:()=>void)=>{listeners.add(listener);return ()=>{listeners.delete(listener);};};
export const getSnapshot=()=>current;
export const useSnapshot=()=>useSyncExternalStore(subscribe,getSnapshot);
export const projection=()=>session.getBoardProjection();
export const keyboard=()=>session.getKeyboardState();
export const remaining=()=>session.getRemainingAttempts();
export const review={
    async connect(endpoint:string,csrf:string){
        if(lease?.active)throw new Error('Already connected');
        lease=new LeasedClient(webTransport(endpoint,csrf),()=>{if(lease?.session)session=lease.session;publish();});
        await lease.acquire();return session.snapshot();
    },
    checkpoint:()=>{if(!lease)throw new Error('Not connected');return lease.checkpoint();},
    release:()=>{if(!lease)throw new Error('Not connected');return lease.release();},
    storageStatus,
    snapshot:()=>session.snapshot(),
    prepareHandoff:()=>act(s=>s.prepareHandoff()),
    async restore(snapshot:unknown){
        if(lease)throw new Error('Import unavailable in caller-scoped mode');
        if(restoring) throw new Error('Restore in progress');
        restoring=true;
        try { const next=await WordwrightSession.restore(snapshot); session=next; publish(); }
        finally {restoring=false;}
    },
};
declare global { interface Window { wordwrightReview:typeof review; wordwrightSnapshot?:()=>Snapshot; wordwrightHost?:{endpoint:string;csrfToken:string;onReturn:()=>void}; } }
if(!window.wordwrightHost)window.wordwrightReview=review;
else window.wordwrightSnapshot=()=>session.snapshot();
