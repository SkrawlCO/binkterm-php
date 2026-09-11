import { useEffect, useMemo } from "react";
import { useDelayedFlag } from "../hooks/useDelayedFlag.ts";
import { useKeyboard } from "../hooks/useKeyboard.ts";
import { useNumPadPosition } from "../hooks/useNumPadPosition.ts";
import { useNumpadInteractions } from "../hooks/useNumpadInteractions.ts";
import { useSudoku } from "../hooks/useSudoku.ts";
import type { DokuelSession } from "../../../session.ts";
import { formatTime } from "../lib/format.ts";
import { cellKey } from "../lib/sudoku.ts";
import { AssistLevelPicker } from "./AssistLevelPicker.tsx";
import { Board } from "./Board.tsx";
import { DigitDragIndicator } from "./DigitDragIndicator.tsx";
import { GameControls } from "./GameControls.tsx";
import { GameLayout } from "./GameLayout.tsx";
import { GameResult } from "./GameResult.tsx";
import { HintBanner } from "./HintBanner.tsx";
import { NumPad } from "./NumPad.tsx";
import { TimerPill } from "./TimerPill.tsx";

const EMPTY_CONFLICTS = new Set<number>();

type SoloGameProps = {
  session: DokuelSession;
  onBack: () => void;
  onReview?: (() => void) | undefined;
};

export function SoloGame({ session, onBack, onReview }: SoloGameProps) {
  const game = useSudoku(session);
  const { assistLevel, setAssistLevel, paused, setPaused, elapsedSeconds } = game;
  const { difficulty, identity } = session.view();
  const isDaily = identity.kind === "daily";
  const title = isDaily ? `Daily Challenge — ${identity.date}` : `Dokuel · ${difficulty[0]!.toUpperCase()}${difficulty.slice(1)}`;
  const { position, setPosition } = useNumPadPosition();
  const revealed = useDelayedFlag(true, 600);
  const showResult = useDelayedFlag(game.status === "completed", 300);
  const {
    highlight,
    chargingDigit,
    keyDigit,
    numPadRef,
    numPadProps,
    dragState,
    startCellDrag,
  } = useNumpadInteractions({
    game,
    disabled: paused || game.status !== "playing",
    assistLevel,
  });

  const handleBack = () => {
    if (
      game.status === "playing" &&
      game.historyLength > 0 &&
      !window.confirm("Return to the puzzle menu? Your current game stays available to continue.")
    ) {
      return;
    }
    if (game.status === "playing") setPaused(true);
    onBack();
  };

  // Auto-pause when tab loses visibility
  useEffect(() => {
    const handleVisibility = () => {
      if (document.hidden && game.status === "playing") {
        setPaused(true);
      }
    };
    document.addEventListener("visibilitychange", handleVisibility);
    return () =>
      document.removeEventListener("visibilitychange", handleVisibility);
  }, [game.status, setPaused]);

  useKeyboard({
    selectedCell: game.selectedCell,
    onSelectCell: game.selectCell,
    onDeselectCell: game.deselectCell,
    onPlaceNumber: keyDigit,
    onErase: game.erase,
    onUndo: game.undo,
    onToggleNotes: game.toggleNotesMode,
    enabled: game.status === "playing" && !paused,
  });

  const hintCells = useMemo(() => {
    if (!game.activeHint) return undefined;
    const set = new Set<number>();
    for (const pos of game.activeHint.relatedCells) {
      set.add(cellKey(pos.row, pos.col));
    }
    return set;
  }, [game.activeHint]);

  return (
    <GameLayout
      onBack={handleBack}
      title={title}
      position={position}
      onPositionChange={setPosition}
      onDeselectCell={highlight.deselectCell}
      boardClassName={game.status === "completed" ? "animate-celebration" : ""}
      settingsExtra={
        <AssistLevelPicker value={assistLevel} onChange={setAssistLevel} />
      }
      timer={
        <TimerPill
          seconds={elapsedSeconds}
          onClick={() => game.status === "playing" && setPaused(!paused)}
          ariaLabel={paused ? "Resume" : "Pause"}
          subline={
            paused ? (
              "Paused"
            ) : (
              <>
                <span className="text-accent font-medium">
                  {81 - game.cellsRemaining}
                </span>
                /81

              </>
            )
          }
        />
      }
      numPad={<NumPad ref={numPadRef} position={position} {...numPadProps} />}
      board={
        <div className="relative w-full">
          <Board
            board={game.board}
            selectedCell={paused ? null : game.selectedCell}
            selectedCells={paused ? undefined : game.selectedCells}
            assistLevel={assistLevel}
            conflicts={assistLevel !== "paper" ? game.errors : EMPTY_CONFLICTS}
            hintCells={hintCells}
            highlightedDigit={paused ? null : highlight.highlightedDigit}
            onSelectCell={paused ? () => {} : highlight.selectCell}
            onSetSelectedCells={paused ? undefined : highlight.setSelectedCells}
            animateReveal={!revealed}
            chargingDigit={paused ? null : chargingDigit}
            dragState={paused ? null : dragState}
            onStartCellDrag={paused ? undefined : startCellDrag}
          />
          <DigitDragIndicator state={paused ? null : dragState} />
          {paused && (
            <button
              type="button"
              className="absolute inset-0 flex items-center justify-center bg-bg-primary/80 backdrop-blur-md rounded-lg"
              onClick={() => setPaused(false)}
              aria-label="Resume game"
            >
              <span className="text-xl font-semibold text-text-muted">
                Paused — tap to resume
              </span>
            </button>
          )}
        </div>
      }
      controls={
        <>
          {game.activeHint && (
            <HintBanner hint={game.activeHint} onDismiss={game.dismissHint} />
          )}
          <GameControls
            onErase={game.erase}
            onUndo={game.undo}
            historyLength={game.historyLength}
            onHint={game.hint}
          />
        </>
      }
      footer={
        showResult ? (
          <GameResult
            isWinner={true}
            time={formatTime(elapsedSeconds)}
            timeSeconds={elapsedSeconds}
            difficulty={difficulty}
            onNewGame={onBack}
            onReview={onReview}
            hintsUsed={game.hintsUsed}
            isDaily={isDaily}

          />
        ) : undefined
      }
    />
  );
}
