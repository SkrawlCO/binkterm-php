import { DokuelSession, type Snapshot } from '../session.ts';
export type Request = (body: Record<string, unknown>) => Promise<any>;
export declare const canWrite: (session: DokuelSession) => boolean;
/** Serializes the existing generalized lease protocol around exact shared snapshots. */
export declare class LeasedClient {
    private request;
    private changed;
    private intervalMs;
    session: DokuelSession;
    envelope: any;
    active: boolean;
    error: string;
    private tail;
    private heartbeat;
    private closing;
    constructor(request: Request, changed?: () => void, intervalMs?: number);
    private call;
    acquire(): Promise<Snapshot>;
    replace(session: DokuelSession): void;
    mutate<T>(fn: (session: DokuelSession) => T): T;
    private fail;
    private enqueue;
    private send;
    checkpoint(): Promise<Snapshot>;
    release(): Promise<Snapshot>;
    get writable(): boolean;
    abandon(): void;
}
export declare function webTransport(endpoint: string, csrf: string): Request;
