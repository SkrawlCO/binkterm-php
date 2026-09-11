import { LeasedClient, webTransport } from '../../persistence/client.ts';
import { useState, useReducer, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { DokuelSession } from '../../session.ts';
import { DifficultyPicker } from './components/DifficultyPicker.tsx';
import { SoloGame } from './components/SoloGame.tsx';
import { useDarkMode } from './hooks/useDarkMode.ts';
import './index.css';
import './review.css';

declare global { interface Window { dokuelHost?: { endpoint: string; csrfToken: string; onReturn: () => void }; dokuelSnapshot?: () => unknown; } }
function App() {
    const [screen, setScreen] = useState<'home' | 'difficulty' | 'game' | 'review'>('home');
    const [session, setSession] = useState<DokuelSession | null>(null);
    const [text, setText] = useState('');
    const [error, setError] = useState('');
    const [lease, setLease] = useState<LeasedClient | null>(null);
    const [, refresh] = useReducer(n => n + 1, 0);
    useDarkMode();
    useEffect(() => {
        if (window.dokuelHost) connect(window.dokuelHost.endpoint, window.dokuelHost.csrfToken).catch(() => {});
    }, []);
    async function connect(endpoint: string, csrf: string) {
        if (lease?.active) throw Error('Already connected');
        const next = new LeasedClient(webTransport(endpoint, csrf), refresh);
        setLease(next);
        try { await next.acquire(); setSession(next.session); setScreen('game'); }
        catch (e) { setError(String(e)); throw e; }
    }
    async function release() {
        if (!lease) throw Error('Not connected');
        const saved = await lease.release(); setScreen('home'); window.dokuelHost?.onReturn(); return saved;
    }
    // Explicit local host/review hook; authentication is always enforced by PHP.
    if (!window.dokuelHost) (window as any).dokuelReview = {
        connect, snapshot: () => session?.snapshot(), view: () => session?.view(),
        checkpoint: () => lease!.checkpoint(), release,
        storageStatus: () => ({ active: lease?.writable, error: lease?.error, revision: lease?.envelope?.revision }),
    };
    if (window.dokuelHost) window.dokuelSnapshot = () => session?.snapshot();
    function start(next: DokuelSession) {
        if (lease) lease.replace(next);
        setSession(next);
        setScreen('game');
    }
    function review() {
        // Reviewing is an explicit pause; export records that authoritative state.
        if (session?.view().state.status === 'playing') session.pause();
        setText(session?.export() ?? '');
        setError('');
        setScreen('review');
    }
    function content() {
    if (screen === 'game' && session) return <>
        <SoloGame session={session} onBack={() => setScreen('home')} onReview={window.dokuelHost ? undefined : review} />
        {!window.dokuelHost && <div className="review-link"><button className="btn btn-secondary btn-md" onClick={review}>Review snapshot</button></div>}
    </>;
    if (screen === 'difficulty') return <main className="screen"><DifficultyPicker
        onBack={() => setScreen('home')}
        onSelect={(difficulty, assist) => start(DokuelSession.startSolo(difficulty, crypto.randomUUID(), assist))}
    /></main>;
    if (screen === 'review') return <main className="screen"><section className="screen-content gap-4 py-6">
        <h1 className="heading">Review snapshot</h1>
        <p className="caption">Copy this snapshot to keep an exact local session. Paste a snapshot to restore it. Opening review pauses play.</p>
        <label className="w-full caption" htmlFor="snapshot">Session snapshot</label>
        <textarea id="snapshot" value={text} onChange={event => setText(event.target.value)} spellCheck={false} />
        {error && <p role="alert" className="text-danger">{error}</p>}
        <button className="btn btn-primary btn-md w-full" onClick={() => {
            try { if (lease) throw Error('Review import disabled while connected'); start(DokuelSession.restore(text)); } catch (e) { setError(e instanceof Error ? e.message : 'Invalid snapshot'); }
        }}>Restore snapshot</button>
        <button className="btn btn-secondary btn-md w-full" disabled={!session} onClick={() => {
            setText(session!.export()); setError('');
        }}>Export snapshot</button>
        <button className="btn btn-secondary btn-md w-full" onClick={() => setScreen(session ? 'game' : 'home')}>Back</button>
    </section></main>;
    return <main className="screen"><section className="screen-content gap-6 py-10">
        <header className="text-center"><h1 className="heading-xl">Dokuel</h1><p className="caption mt-2">Sudoku, at your pace.</p></header>
        <button className="btn btn-primary btn-lg w-full" onClick={() => setScreen('difficulty')}>Start Solo</button>
        <button className="btn btn-secondary btn-lg w-full" onClick={() => start(DokuelSession.startDaily())}>Daily Challenge</button>
        {session && <button className="btn btn-secondary btn-md w-full" onClick={() => setScreen('game')}>Continue</button>}
        {!window.dokuelHost && <button className="btn btn-secondary btn-md w-full" onClick={review}>Review snapshot</button>}
        <a className="caption" href="https://github.com/adrienbrault/dokuel">Dokuel · MIT · Adrien Brault</a>
    </section></main>;
    }
    return <>
        {lease && <section className="review-link" aria-label="Caller save">
            <p role="status">{lease.error ? `Save stopped: ${lease.error}` : lease.writable ? `Caller save · revision ${lease.envelope?.revision}` : 'Writer released or connecting'}</p>
            <button className="btn btn-secondary btn-md" disabled={!lease.writable} onClick={() => { lease.checkpoint().catch(() => {}); }}>Checkpoint</button>
            <button className="btn btn-primary btn-md" disabled={!lease.writable} onClick={() => { release().catch(() => {}); }}>Save &amp; Return</button>
        </section>}
        <div inert={lease !== null && !lease.writable}>{content()}</div>
    </>;
}

createRoot(document.getElementById('root')!).render(<App />);
