import { useRef, useState } from "react";
import type { NumPadHandle } from "../components/NumPad.tsx";
import {
  applyDigitIntent,
  type DigitGesture,
  type DigitIntentOps,
  digitIntent,
} from "../lib/digit-intent.ts";
import type { AssistLevel } from "../lib/types.ts";
import { useDigitHighlight } from "./useDigitHighlight.ts";
import { useGameDigitDrag } from "./useGameDigitDrag.ts";
import type { useSudoku } from "./useSudoku.ts";

type SudokuGame = ReturnType<typeof useSudoku>;

/**
 * Everything the touch numpad needs to talk to a game: digit
 * highlighting, hold-to-note charging, drag-and-drop wiring, and the
 * shared NumPad props. SoloGame and MultiplayerBoard used to carry
 * verbatim copies of all of this (ADR 0001 keeps the components
 * separate, but the input wiring is exactly the "shared surface" it
 * counts — sharing it here guarantees solo and multiplayer input
 * behavior cannot drift).
 */
export function useNumpadInteractions({
  game,
  disabled,
  assistLevel,
}: {
  game: SudokuGame;
  disabled: boolean;
  assistLevel: AssistLevel;
}) {
  const [chargingDigit, setChargingDigit] = useState<number | null>(null);
  const highlight = useDigitHighlight(game);
  const autoEliminateNotes = assistLevel !== "paper";

  // What a digit does, and what it says it does, both come from
  // digitIntent — see src/lib/digit-intent.ts. This is the only place
  // that turns an intent into game calls, so the ordering the engine
  // requires (place before the selection moves) is stated once.
  const ops: DigitIntentOps = {
    placeNumber: (value, asNote) =>
      game.placeNumber(value, autoEliminateNotes, asNote),
    placeNoteAt: game.placeNoteAt,
    selectCell: game.selectCell,
    deselectCell: game.deselectCell,
    toggleHighlight: highlight.toggle,
    setHighlight: highlight.setDigit,
  };
  const intentFor = (gesture: DigitGesture) =>
    digitIntent(gesture, {
      board: game.board,
      selectedCell: game.selectedCell,
      selectedCells: game.selectedCells,
    });
  const runIntent = (gesture: DigitGesture, digit: number) =>
    applyDigitIntent(intentFor(gesture), digit, ops);

  const handleHoldNote = (n: number) => {
    const intent = intentFor({ kind: "hold" });
    applyDigitIntent(intent, n, ops);
    // The charge animation runs the digit into a note slot, so it only
    // plays when the hold had somewhere to pencil.
    if (intent.effect.kind !== "none") setChargingDigit(n);
  };

  // The keyboard's digit keys, which follow the N-toggled notes flag
  // rather than the selection shape. Multiplayer has no keyboard path.
  const keyDigit = (n: number) =>
    runIntent({ kind: "key", notesMode: game.notesMode }, n);

  const handlePressEnd = () => {
    setChargingDigit(null);
  };

  // Digit drag: top-half drop commits a value, bottom-half adds a note.
  // A drag brought back over the numpad demotes to a skim (see NumPad).
  const numPadRef = useRef<NumPadHandle>(null);
  const { dragState, startNumpadDrag, startCellDrag } = useGameDigitDrag({
    board: game.board,
    disabled,
    onDrop: ({ digit, mode, target, from }) =>
      runIntent({ kind: "drop", mode, target, from }, digit),
    onReturnToNumpad: (info) => numPadRef.current?.resumeSkimFromDrag(info),
  });

  // Prop bag for <NumPad {...numPadProps} ref={numPadRef} position=.../>.
  const numPadProps = {
    // The legend reads the same intent the tap will run.
    tapAction: intentFor({ kind: "tap" }).label,
    remainingCounts: game.remainingCounts,
    selectedValue: game.selectedCell
      ? game.board[game.selectedCell.row]![game.selectedCell.col]!.value
      : highlight.highlightedDigit,
    showRemainingCounts: assistLevel === "full",
    disableCompleted: assistLevel !== "paper",
    onTapNumber: (n: number) => runIntent({ kind: "tap" }, n),
    onHoldNumber: handleHoldNote,
    onPressEnd: handlePressEnd,
    onStartDrag: startNumpadDrag,
    onSkimDigit: highlight.skimToDigit,
  };

  return {
    highlight,
    chargingDigit,
    keyDigit,
    numPadRef,
    numPadProps,
    dragState,
    startCellDrag,
  };
}
