/**
 * Generic caller-scoped lease client for Parlour, following the same
 * pattern as the sibling games' `persistence/client.ts` (Dokuel/Wordwright):
 * a transport-agnostic `LeasedClient` driven by a `Request` function, plus
 * a `webTransport()` helper for the browser. The terminal adapter supplies
 * its own transport (a JSON-lines child process speaking to
 * `persistence/helper.php`) rather than `webTransport`, but drives the same
 * `LeasedClient` class — one continuity contract, two transports.
 */
import {
  ParlourLeaseSession,
  assertCallerScopedEnvelope,
  type ParlourLeaseEnvelope,
  type ParlourMode,
} from './envelope';
import type { GameId } from '../facade/index';
import type { RuleValues } from '../vendor/packages/engine/src/types';

export type Request = (body: Record<string, unknown>) => Promise<any>;

const guards = new WeakMap<ParlourLeaseSession, () => boolean>();
export const canWrite = (session: ParlourLeaseSession): boolean => guards.get(session)?.() ?? true;

/**
 * Serializes the existing generalized lease protocol (acquire/save/renew/
 * release) around exact `ParlourLeaseSession` snapshots. Mirrors Dokuel's
 * `LeasedClient` line for line in control flow; only the session type and
 * schema/revision incompatibility check differ.
 */
export class LeasedClient {
  session!: ParlourLeaseSession;
  envelope: any;
  active = false;
  error = '';
  private tail: Promise<unknown> = Promise.resolve();
  private heartbeat: ReturnType<typeof setInterval> | undefined;
  private closing: Promise<ParlourLeaseEnvelope> | undefined;

  constructor(
    private request: Request,
    private changed: () => void = () => {},
    private intervalMs = 10000,
  ) {}

  private async call(body: Record<string, unknown>) {
    let timer: ReturnType<typeof setTimeout>;
    try {
      const result = await Promise.race([
        this.request(body),
        new Promise<never>((_, reject) => {
          timer = setTimeout(() => reject(new Error('Storage timeout')), 8000);
        }),
      ]);
      if (!result.success) throw new Error(result.reason || 'Writer conflict');
      return result;
    } finally {
      clearTimeout(timer!);
    }
  }

  /** Acquires the lease and restores the caller's existing session, or starts a fresh one via `startNew`. */
  async acquire(startNew: () => ParlourLeaseSession): Promise<ParlourLeaseEnvelope> {
    if (this.envelope) throw new Error('Already acquired');
    try {
      this.envelope = await this.call({ action: 'acquire' });
      const hasSaved = this.envelope.data && Object.keys(this.envelope.data).length > 0;
      if (hasSaved) {
        assertCallerScopedEnvelope(this.envelope.data);
        this.session = ParlourLeaseSession.restore(this.envelope.data);
      } else {
        this.session = startNew();
      }
      guards.set(this.session, () => this.writable);
      this.active = true;
      this.changed();
      this.heartbeat = setInterval(() => {
        this.checkpoint().catch(() => {});
      }, this.intervalMs);
      (this.heartbeat as any).unref?.();
      return this.session.snapshot();
    } catch (e) {
      if (this.envelope) await this.call({ action: 'release', owner_token: this.envelope.owner_token }).catch(() => {});
      this.fail(e);
      throw e;
    }
  }

  /**
   * Game switching: replaces the active session in place (still slot 0 —
   * one active caller Parlour session at a time, per the brief's preferred
   * model). The prior game's final state is not persisted again unless the
   * caller checkpoints first; callers that want a last checkpoint of the
   * old game should call `checkpoint()` immediately before `replace()`.
   */
  replace(session: ParlourLeaseSession): void {
    if (!this.writable) throw new Error('Writer inactive');
    this.session = session;
    guards.set(session, () => this.writable);
    this.changed();
  }

  mutate<T>(fn: (session: ParlourLeaseSession) => T): T {
    if (!this.active || this.closing) throw new Error('Writer inactive');
    const value = fn(this.session);
    this.changed();
    return value;
  }

  private fail(e: unknown): void {
    clearInterval(this.heartbeat);
    this.active = false;
    this.error = e instanceof Error ? e.message : 'Storage failed';
    this.changed();
  }

  private enqueue<T>(fn: () => Promise<T>): Promise<T> {
    const next = this.tail.then(() => {
      if (!this.active) throw new Error('Writer inactive');
      return fn();
    });
    this.tail = next.catch((e) => this.fail(e)) as Promise<unknown>;
    return next;
  }

  private async send(action: string, data?: ParlourLeaseEnvelope) {
    const result = await this.call({
      action,
      owner_token: this.envelope.owner_token,
      attempt_id: this.envelope.attempt_id,
      revision: this.envelope.revision,
      ...(data ? { data } : {}),
    });
    this.envelope = { ...this.envelope, ...result };
    return result;
  }

  checkpoint(): Promise<ParlourLeaseEnvelope> {
    if (this.closing) return Promise.reject(new Error('Writer closing'));
    return this.enqueue(async () => {
      const snapshot = this.session.snapshot();
      assertCallerScopedEnvelope(snapshot);
      this.changed();
      await this.send('save', snapshot);
      this.changed();
      return snapshot;
    });
  }

  release(): Promise<ParlourLeaseEnvelope> {
    if (this.closing) return this.closing;
    clearInterval(this.heartbeat);
    // Suspend input immediately; queue save after any in-flight checkpoint.
    this.closing = this.enqueue(async () => {
      const snapshot = this.session.snapshot();
      assertCallerScopedEnvelope(snapshot);
      this.changed();
      await this.send('save', snapshot);
      await this.send('release');
      this.active = false;
      this.changed();
      return snapshot;
    });
    this.changed();
    return this.closing;
  }

  get writable(): boolean {
    return this.active && !this.closing;
  }

  abandon(): void {
    clearInterval(this.heartbeat);
    this.active = false;
    this.changed();
  }
}

export function webTransport(endpoint: string, csrf: string): Request {
  const url = new URL(endpoint, location.href);
  if (url.origin !== location.origin) throw new Error('Storage must be same origin');
  return async (body) => {
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body),
    });
    if (!response.ok) throw new Error(`Storage HTTP ${response.status}`);
    return response.json();
  };
}

export type { ParlourLeaseEnvelope, ParlourMode, GameId };
