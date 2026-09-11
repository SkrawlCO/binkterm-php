import { WordwrightSession, type Snapshot } from '../session.js';
export type Request = (body: Record<string, unknown>) => Promise<any>;
/** Single serialized lease owner. Game/meta/stats are one opaque atomic value. */
export declare class LeasedClient {
    private request;
    private changed;
    private intervalMs;
    session: WordwrightSession;
    envelope: any;
    active: boolean;
    error: string;
    private tail;
    private heartbeat;
    private closing;
    constructor(request: Request, changed?: () => void, intervalMs?: number);
    private call;
    acquire(): Promise<Snapshot>;
    mutate<T>(fn: (session: WordwrightSession) => T): T;
    private fail;
    private enqueue;
    private send;
    checkpoint(): Promise<Snapshot>;
    release(): Promise<Snapshot>;
    get writable(): boolean;
    abandon(): void;
}
export declare function webTransport(endpoint: string, csrf: string): Request;
