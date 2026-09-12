'use client';

/**
 * Parlour's caller-scoped PERSISTED session surface (Slice 5 production
 * integration). Deliberately separate from the 23 rich canonical game pages
 * (`/klondike`, `/hearts`, ...), which are byte-identical to upstream and
 * have no save/resume concept at all — this page is additive, not a
 * modification of any canonical page. It reuses:
 *   - the real canonical `PlayingCard` component (`@/components/table/PlayingCard`)
 *   - the shared facade + persistence envelope/lease client (`@/lib/parlourLease`)
 *   - the same family/catalog metadata the terminal adapter uses, so the two
 *     surfaces present a consistent (if web-simple) view of the same game.
 *
 * Everything here is presentation only: legality, dealing, bots, and replay
 * are all the same facade calls the terminal adapter and the persistence
 * tests already exercise. No card rule is reimplemented.
 */
import { useEffect, useMemo, useState } from 'react';
import { PlayingCard } from '@/components/table/PlayingCard';
import {
  LeasedClient,
  ParlourLeaseSession,
  webTransport,
  canWrite,
  CATALOG,
  type ParlourMode,
  type GameId,
} from '@/lib/parlourLease';

const REPRESENTATIVE: { id: GameId; label: string; seats: number; mode: ParlourMode }[] = [
  { id: 'klondike', label: 'Klondike (solitaire)', seats: 1, mode: 'solo' },
  { id: 'spider', label: 'Spider (solitaire)', seats: 1, mode: 'solo' },
  { id: 'pyramid', label: 'Pyramid (solitaire)', seats: 1, mode: 'solo' },
  { id: 'hearts', label: 'Hearts (vs. bots)', seats: 4, mode: 'solo-vs-bots' },
  { id: 'euchre', label: 'Euchre (vs. bots)', seats: 4, mode: 'solo-vs-bots' },
  { id: 'durak', label: 'Durak (vs. bots)', seats: 2, mode: 'solo-vs-bots' },
  { id: 'cribbage', label: 'Cribbage (vs. bots)', seats: 2, mode: 'solo-vs-bots' },
  { id: 'gin', label: 'Gin Rummy (vs. bots)', seats: 2, mode: 'solo-vs-bots' },
];

function isCardId(v: unknown): v is string {
  return typeof v === 'string' && /^([SHDC])(\d{1,2}|A|K|Q|J|10)(-\d+)?$/.test(v);
}

function moveText(id: string, payload: unknown): { label: string; card?: string } {
  if (payload && typeof payload === 'object') {
    const entries = Object.entries(payload as Record<string, unknown>);
    const cardEntry = entries.find(([, v]) => isCardId(v));
    if (cardEntry) return { label: id, card: cardEntry[1] as string };
    return { label: `${id} ${entries.map(([k, v]) => `${k}=${JSON.stringify(v)}`).join(' ')}` };
  }
  if (isCardId(payload)) return { label: id, card: payload };
  return { label: payload === undefined ? id : `${id} ${JSON.stringify(payload)}` };
}

function returnToPP(): void {
  try {
    const link = window.parent.document.getElementById('webdoor-return') as HTMLAnchorElement | null;
    const target = link ? new URL(link.href, location.origin) : new URL('/experiences/parlour', location.origin);
    if (target.origin === location.origin) {
      window.parent.location.assign(target.pathname + target.search + target.hash);
      return;
    }
  } catch {
    /* standalone (non-iframed) access below */
  }
  window.location.assign('/experiences/parlour');
}

export default function ContinuePage() {
  const [lease, setLease] = useState<LeasedClient | null>(null);
  const [, force] = useState(0);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorText, setErrorText] = useState('');

  useEffect(() => {
    // Constructed here, not during render: this static-exported page is
    // still prerendered at build time even though it is 'use client', and
    // `webTransport`/`getCsrfToken` need a real browser (`window`, `fetch`).
    const client = new LeasedClient(webTransport('/webdoors/parlour/api.php', getCsrfToken()));
    setLease(client);
    (client as unknown as { changed: () => void }).changed = () => force((n) => n + 1);
    client
      .acquire(() => ParlourLeaseSession.create('klondike', Date.now() & 0xffffffff, {}, 1, { mode: 'solo' }))
      .then(() => setStatus('ready'))
      .catch((e: unknown) => {
        setStatus('error');
        setErrorText(e instanceof Error ? e.message : 'Could not reach Parlour storage');
      });
    return () => {
      client.abandon();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const session = lease?.session;
  const writable = session ? canWrite(session) : false;
  const items = useMemo(() => (session ? session.legalMoves() : []), [session, status]);

  function startNew(entry: (typeof REPRESENTATIVE)[number]) {
    if (!lease || !writable) return;
    lease.checkpoint().catch(() => {}); // best-effort last checkpoint of the old game
    const fresh = ParlourLeaseSession.create(entry.id, Date.now() & 0xffffffff, {}, entry.seats, { mode: entry.mode });
    lease.replace(fresh);
    lease.checkpoint().catch(() => {});
    force((n) => n + 1);
  }

  function act(moveId: string, payload?: unknown) {
    if (!lease || !session || !writable) return;
    const outcome = session.act(moveId, payload);
    if (!outcome.rejected) lease.checkpoint().catch(() => {});
    force((n) => n + 1);
  }

  async function saveAndReturn() {
    try {
      await lease?.release();
    } finally {
      returnToPP();
    }
  }

  if (status === 'loading') return <main style={styles.page}>Connecting to your saved Parlour session…</main>;
  if (status === 'error')
    return (
      <main style={styles.page}>
        <p style={{ color: '#ff6ec7' }}>Could not load your saved session: {errorText}</p>
        <p>Your progress is safe — nothing was written. Try again, or start a new game from the chooser.</p>
        <a href="/webdoors/parlour/assets/">Go to the full Parlour chooser</a>
      </main>
    );

  const view = session ? session.snapshot() : null;
  const humanHand: string[] = (() => {
    if (!session) return [];
    const s = (session as unknown as { game: { session: { state: unknown } } }).game.session.state as Record<
      string,
      unknown
    >;
    const round = (s.round ?? s.hand ?? s) as Record<string, unknown>;
    const hands = (round.hands ?? round.piles) as unknown[][] | undefined;
    return (hands?.[session.humanSeat] as string[] | undefined) ?? [];
  })();

  return (
    <main style={styles.page}>
      <h1 style={styles.h1}>Parlour — Your Session</h1>
      <p style={styles.sub}>
        {view ? (
          <>
            Playing <strong>{view.gameId}</strong> · seed {view.seed} · seat {view.humanSeat}/{view.seats} ·{' '}
            {view.mode}
          </>
        ) : (
          'No saved game yet — start one below.'
        )}
        {!writable && <span style={{ color: '#ff6ec7' }}> · storage lease lost, further moves are blocked</span>}
      </p>

      {session && session.completion() && (
        <p style={{ color: '#6ee7ff' }}>
          Game over — {JSON.stringify(session.completion())}
        </p>
      )}

      {session && humanHand.length > 0 && (
        <section style={styles.section}>
          <h2 style={styles.h2}>Your hand</h2>
          <div style={styles.row}>
            {humanHand.map((id, i) => (
              <PlayingCard key={`${id}-${i}`} card={id} compact />
            ))}
          </div>
        </section>
      )}

      {session && items.length > 0 && (
        <section style={styles.section}>
          <h2 style={styles.h2}>Your move</h2>
          <div style={styles.row}>
            {items.map((m, i) => {
              const t = moveText(m.id, m.payload);
              return (
                <button key={i} style={styles.moveBtn} disabled={!writable} onClick={() => act(m.id, m.payload)}>
                  {t.card ? <PlayingCard card={t.card} compact /> : null}
                  <span>{m.hint ?? t.label}</span>
                </button>
              );
            })}
          </div>
        </section>
      )}

      <section style={styles.section}>
        <h2 style={styles.h2}>Start a new persisted game</h2>
        <div style={styles.row}>
          {REPRESENTATIVE.map((entry) => (
            <button key={entry.id} style={styles.newGameBtn} disabled={!writable} onClick={() => startNew(entry)}>
              {entry.label}
            </button>
          ))}
        </div>
        <p style={styles.hint}>
          Starting a new game replaces your current Parlour session (checkpointed first) — one active session at a
          time, same as your terminal continuation.
        </p>
      </section>

      <div style={styles.row}>
        <button style={styles.saveBtn} onClick={saveAndReturn}>
          Save &amp; Return
        </button>
        <a style={styles.link} href="/webdoors/parlour/assets/">
          Play a fresh, unsaved game in the full chooser instead
        </a>
      </div>
    </main>
  );
}

function getCsrfToken(): string {
  const w = window as unknown as { parlourHost?: { csrfToken?: string } };
  return w.parlourHost?.csrfToken ?? '';
}

const styles: Record<string, React.CSSProperties> = {
  page: {
    minHeight: '100dvh',
    background: '#160a24',
    color: '#f3e9ff',
    fontFamily: 'ui-sans-serif, system-ui, sans-serif',
    padding: '20px 16px',
    boxSizing: 'border-box',
  },
  h1: { fontSize: '1.4rem', margin: '0 0 4px' },
  h2: { fontSize: '1rem', margin: '0 0 8px', color: '#8B5CFF' },
  sub: { color: '#c9b8e0', marginBottom: '16px' },
  section: { marginBottom: '20px' },
  row: { display: 'flex', flexWrap: 'wrap', gap: '10px', alignItems: 'center' },
  hint: { color: '#9a86b8', fontSize: '0.85rem', marginTop: '8px' },
  moveBtn: {
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'center',
    gap: '4px',
    background: '#2b0f3a',
    color: '#f3e9ff',
    border: '1px solid #8B5CFF',
    borderRadius: '10px',
    padding: '8px 12px',
    cursor: 'pointer',
  },
  newGameBtn: {
    background: '#2b0f3a',
    color: '#6EE7FF',
    border: '1px solid #6EE7FF',
    borderRadius: '10px',
    padding: '10px 14px',
    cursor: 'pointer',
  },
  saveBtn: {
    background: 'linear-gradient(90deg,#6EE7FF,#8B5CFF,#FF6EC7)',
    color: '#160a24',
    fontWeight: 800,
    border: 'none',
    borderRadius: '10px',
    padding: '12px 20px',
    cursor: 'pointer',
  },
  link: { color: '#6EE7FF', alignSelf: 'center' },
};
