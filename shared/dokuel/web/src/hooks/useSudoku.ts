import { canWrite } from '../../../persistence/client.ts';
import { useCallback, useEffect, useMemo, useReducer, useRef } from 'react';
import type { DokuelSession, AssistLevel } from '../../../session.ts';
import type { Position } from '../lib/types.ts';
import { cellKey } from '../lib/sudoku.ts';
import { gameFeedback } from '../lib/game-feedback.ts';

/** Compatibility facade for canonical presentation. Every game action uses the shared session. */
export function useSudoku(session: DokuelSession) {
    const [, render] = useReducer((n: number) => n + 1, 0);
    const last = useRef(performance.now());
    const tick = useCallback(() => {
        const now = performance.now();
        const delta = Math.floor(now - last.current);
        last.current += delta;
        if (canWrite(session)) session.advanceTime(delta);
    }, [session]);
    const run = useCallback((action: () => void) => {
        if (!canWrite(session)) return;
        tick();
        action();
        render();
    }, [tick]);
    useEffect(() => {
        last.current = performance.now();
        const timer = window.setInterval(() => { tick(); render(); }, 250);
        return () => { tick(); clearInterval(timer); };
    }, [tick]);
    const view = session.view();
    const { state, projection } = view;
    const previousErrors = useRef(projection.errors.size);
    const previousStatus = useRef(state.status);
    useEffect(() => {
        if (projection.errors.size > previousErrors.current) gameFeedback.onConflict();
        if (state.status === 'completed' && previousStatus.current !== 'completed') gameFeedback.onComplete();
        previousErrors.current = projection.errors.size;
        previousStatus.current = state.status;
    }, [projection.errors.size, state.status]);
    const actions = useMemo(() => ({
        selectCell: (row: number, col: number) => run(() => session.selectCell(row, col)),
        deselectCell: () => run(() => session.dispatch({ type: 'DESELECT_CELL' })),
        setSelectedCells: (cells: Set<number>, primary: Position) => run(() => session.dispatch({ type: 'SET_SELECTED_CELLS', cells: [...cells], primary })),
        placeNumber: (value: number, autoEliminateNotes = true, asNote?: boolean) => run(() => {
            session.enterDigit(value, autoEliminateNotes, asNote); gameFeedback.onPlace();
        }),
        placeNoteAt: (row: number, col: number, value: number) => run(() => {
            session.toggleNoteAt(row, col, value); gameFeedback.onPlace();
        }),
        erase: () => run(() => { session.erase(); gameFeedback.onErase(); }),
        undo: () => run(() => session.undo()),
        toggleNotesMode: () => run(() => { session.toggleNotes(); gameFeedback.onToggleNotes(); }),
        hint: () => run(() => { session.requestHint(); gameFeedback.onHint(); }),
        dismissHint: () => run(() => session.dismissHint()),
        setPaused: (paused: boolean) => run(() => paused ? session.pause() : session.resume()),
        setAssistLevel: (level: AssistLevel) => run(() => session.setAssistLevel(level)),
    }), [session, run]);
    return {
        ...actions, ...projection, board: state.board, puzzle: view.puzzle, status: state.status,
        selectedCell: state.selectedCell, selectedCells: state.selectedCells, notesMode: state.notesMode,
        historyLength: state.history.length, hintsUsed: state.hintsUsed, activeHint: state.activeHint,
        cellKey, paused: view.paused, elapsedSeconds: Math.floor(view.elapsedMs / 1000), assistLevel: view.assistLevel,
    };
}
