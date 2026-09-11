import { review, useSnapshot, storageStatus } from './bridge';
import { useState } from 'react';

import { GameView } from './components/game/GameView';
import { ErrorBoundary } from './components/layout/ErrorBoundary';
import { Header } from './components/layout/Header';
import { SettingsModal } from './components/settings/SettingsModal';
import { StatsModal } from './components/stats/StatsModal';
import { ToastRegion } from './components/ui/ToastRegion';
import type { WordLength } from './engine';
import {
  GameProvider,
  SettingsProvider,
  StatsProvider,
  ToastProvider,
  useGame,
  useToast,
} from './state';

/**
 * Everything below the providers.
 *
 * Split out because it consumes the contexts that `App` establishes; a
 * component cannot use a context its own parent provides.
 */
function AppShell(): React.JSX.Element {
  useSnapshot();
  const storage=storageStatus();
  const { wordLength, setWordLength, dictionaryStatus } = useGame();
  const { toasts, dismiss } = useToast();

  const [isStatsOpen, setStatsOpen] = useState(false);
  const [isSettingsOpen, setSettingsOpen] = useState(false);

  const handleLengthChange = (length: WordLength): void => {
    if (length === wordLength) return;
    setWordLength(length);
  };

  return (
    <div className="flex min-h-dvh flex-col bg-surface text-text">
      <Header
        wordLength={wordLength}
        lengthDisabled={dictionaryStatus === 'loading'}
        onLengthChange={handleLengthChange}
        onOpenStats={() => {
          setStatsOpen(true);
        }}
        onOpenSettings={() => {
          setSettingsOpen(true);
        }}
      />

      {storage.connected && <div role="status" className="text-center p-2">
        {storage.error || (storage.active ? `Caller state active · revision ${storage.revision}` : 'Writer released or stopped')}
        <button disabled={!storage.active} onClick={()=>{review.checkpoint().catch(()=>{});}}>Checkpoint</button>
        <button disabled={!storage.active} onClick={()=>{review.release().then(()=>window.wordwrightHost?.onReturn()).catch(()=>{});}}>{window.wordwrightHost?'Save & Return':'Save & Release'}</button>
      </div>}
      <GameView />

      <footer className="border-t border-border">
        <div className="mx-auto w-full max-w-2xl px-4 py-2 text-center text-xs text-text-muted">
          {window.wordwrightHost?'Your game and statistics are saved with your account.':'Local review session. Export a snapshot before closing.'}
        </div>
      </footer>


      <ToastRegion toasts={toasts} onDismiss={dismiss} />

      <StatsModal
        isOpen={isStatsOpen}
        onClose={() => {
          setStatsOpen(false);
        }}
      />
      <SettingsModal
        isOpen={isSettingsOpen}
        onClose={() => {
          setSettingsOpen(false);
        }}
      />
    </div>
  );
}

/**
 * Provider order matters (ARCHITECTURE §4):
 *   Settings outermost — theme must apply before anything paints.
 *   Toast next        — every layer below can raise a message.
 *   Stats before Game — the game writes results into stats on completion.
 */
export function App(): React.JSX.Element {
  return (
    <ErrorBoundary>
      <SettingsProvider>
        <ToastProvider>
          <StatsProvider>
            <GameProvider>
              <AppShell />
            </GameProvider>
          </StatsProvider>
        </ToastProvider>
      </SettingsProvider>
    </ErrorBoundary>
  );
}
