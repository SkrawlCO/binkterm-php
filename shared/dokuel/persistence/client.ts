import { DokuelSession, type Snapshot } from '../session.ts';
export type Request = (body:Record<string,unknown>)=>Promise<any>;
const guards = new WeakMap<DokuelSession,()=>boolean>();
export const canWrite = (session:DokuelSession) => guards.get(session)?.() ?? true;
/** Serializes the existing generalized lease protocol around exact shared snapshots. */
export class LeasedClient {
    session!:DokuelSession;
    envelope:any;
    active=false;
    error='';
    private tail:Promise<unknown>=Promise.resolve();
    private heartbeat:ReturnType<typeof setInterval>|undefined;
    private closing:Promise<Snapshot>|undefined;
    constructor(private request:Request, private changed:()=>void=()=>{}, private intervalMs=10000) {}
    private async call(body:Record<string,unknown>){
        let timer:ReturnType<typeof setTimeout>;
        try {
            const result=await Promise.race([this.request(body),new Promise<never>((_,reject)=>{timer=setTimeout(()=>reject(Error('Storage timeout')),8000);})]);
            if(!result.success)throw Error(result.reason||'Writer conflict');return result;
        } finally {clearTimeout(timer!);}
    }
    async acquire(){
        if(this.envelope)throw Error('Already acquired');
        try {
            this.envelope=await this.call({action:'acquire'});
            this.session=Object.keys(this.envelope.data||{}).length?await DokuelSession.restore(this.envelope.data):DokuelSession.startSolo('easy', crypto.randomUUID());
            guards.set(this.session,()=>this.writable);this.active=true;this.changed();
            this.heartbeat=setInterval(()=>{this.checkpoint().catch(()=>{});},this.intervalMs);
            (this.heartbeat as any).unref?.();return this.session.snapshot();
        } catch(e){if(this.envelope)await this.call({action:'release',owner_token:this.envelope.owner_token}).catch(()=>{});this.fail(e);throw e;}
    }
    replace(session:DokuelSession){
        if(!this.writable)throw Error("Writer inactive");
        this.session=session;guards.set(session,()=>this.writable);this.changed();
    }
    mutate<T>(fn:(session:DokuelSession)=>T):T{
        if(!this.active||this.closing)throw Error('Writer inactive');
        const value=fn(this.session);this.changed();return value;
    }
    private fail(e:unknown){clearInterval(this.heartbeat);this.active=false;this.error=e instanceof Error?e.message:'Storage failed';this.changed();}
    private enqueue<T>(fn:()=>Promise<T>):Promise<T>{
        const next=this.tail.then(()=>{if(!this.active)throw Error('Writer inactive');return fn();});
        this.tail=next.catch(e=>this.fail(e));return next;
    }
    private async send(action:string,data?:Snapshot){
        const result=await this.call({action,owner_token:this.envelope.owner_token,attempt_id:this.envelope.attempt_id,revision:this.envelope.revision,...(data?{data}: {})});
        this.envelope={...this.envelope,...result};return result;
    }
    checkpoint(){
        if(this.closing)return Promise.reject(Error('Writer closing'));
        return this.enqueue(async()=>{const snapshot=this.session.snapshot();this.changed();await this.send('save',snapshot);this.changed();return snapshot;});
    }
    release():Promise<Snapshot>{
        if(this.closing)return this.closing;
        clearInterval(this.heartbeat);
        // Suspend input immediately; queue save after any in-flight checkpoint.
        this.closing=this.enqueue(async()=>{
            const snapshot=this.session.snapshot();this.changed();
            await this.send('save',snapshot);await this.send('release');this.active=false;this.changed();return snapshot;
        });this.changed();return this.closing;
    }
    get writable(){return this.active&&!this.closing;}
    abandon(){clearInterval(this.heartbeat);this.active=false;this.changed();}
}
export function webTransport(endpoint:string,csrf:string):Request{
    const url=new URL(endpoint,location.href);
    if(url.origin!==location.origin)throw Error('Storage must be same origin');
    return async body=>{
        const response=await fetch(url,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)});
        if(!response.ok)throw Error(`Storage HTTP ${response.status}`);return response.json();
    };
}
