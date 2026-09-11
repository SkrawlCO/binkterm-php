import { randomUUID } from 'node:crypto';
import { DokuelSession } from '../dist/session.js';

export const coordinate = (row, col) => `${String.fromCharCode(65 + row)}${col + 1}`;
export function move(position, key) {
    const { row, col } = position ?? { row: 0, col: 0 };
    const delta = { up: [-1, 0], w: [-1, 0], down: [1, 0], s: [1, 0], left: [0, -1], a: [0, -1], right: [0, 1], d: [0, 1] }[key];
    return delta ? { row: Math.max(0, Math.min(8, row + delta[0])), col: Math.max(0, Math.min(8, col + delta[1])) } : { row, col };
}
export function ascii(text) {
    return String(text).replace(/[\u2010-\u2015]/g, '-').replace(/[\u2018\u2019]/g, "'")
        .replace(/[\u201c\u201d]/g, '"').replace(/\u2192/g, '->').replace(/\u00d7/g, 'x')
        .replace(/[^\x20-\x7e\n]/g, c => `\\u${c.charCodeAt(0).toString(16).padStart(4, '0')}`);
}
export function wrap(text, width = 74) {
    const lines = [];
    for (const paragraph of ascii(text).split('\n')) {
        let line = '';
        for (let word of paragraph.split(/\s+/).filter(Boolean)) {
            if (line && line.length + 1 + word.length > width) { lines.push(line); line = ''; }
            while (word.length > width) { if (line) { lines.push(line); line = ''; } lines.push(word.slice(0, width)); word = word.slice(width); }
            line += (line ? ' ' : '') + word;
        }
        lines.push(line);
    }
    return lines;
}
export const elapsed = ms => {
    const seconds = Math.floor(ms / 1000);
    return `${Math.floor(seconds / 60).toString().padStart(2, '0')}:${(seconds % 60).toString().padStart(2, '0')}`;
};
export const techniqueName = name => name.split('-').map(w => w[0].toUpperCase() + w.slice(1)).join(' ');

/** Presentation/controller only; all Sudoku decisions come from DokuelSession. */
export class TerminalAdapter {
    constructor(session = null, { exportSnapshot = null } = {}) {
        this.session = session;
        this.screen = session ? 'game' : 'menu';
        this.page = 0;
        this.message = '';
        this.pending = null;
        this.exportSnapshot = exportSnapshot;
        this.quit = false;
        this.small = false;
    }
    start(mode, key = randomUUID(), date) {
        this.session = mode === 'daily' ? DokuelSession.startDaily(date) : DokuelSession.startSolo(mode, key);
        this.screen = 'game'; this.page = 0; this.message = '';
    }
    restore(snapshot) { this.session = DokuelSession.restore(snapshot); this.screen = 'game'; this.page = 0; }
    export() { if (!this.session) throw new Error('No session'); return this.session.export(); }
    advanceTime(ms) { this.session?.advanceTime(ms); }
    resize(columns, rows) {
        this.small = columns < 80 || rows < 24;
        if (this.small) this.session?.pause();
    }
    menu() { this.session?.pause(); this.screen = 'menu'; this.pending = null; }
    key(key) {
        key = key.toLowerCase();
        if (key === 'ctrl-c') { this.quit = true; return; }
        if (this.small) { if (key === 'q') this.quit = true; return; }
        if (this.screen === 'confirm') {
            if (key === 'y') this.start(this.pending);
            else if (key === 'n' || key === 'escape') { this.screen = 'menu'; this.pending = null; }
            return;
        }
        if (this.screen === 'menu') {
            const mode = { '1': 'easy', '2': 'medium', '3': 'hard', '4': 'expert', '5': 'daily' }[key];
            if (mode) {
                if (this.session) { this.pending = mode; this.screen = 'confirm'; }
                else this.start(mode);
            } else if ((key === 'r' || key === 'escape') && this.session) { this.screen = 'game'; }
            else if (key === 'e' && this.session) {
                try {
                    if (!this.exportSnapshot) throw new Error('Start with --snapshot FILE to enable local export.');
                    this.exportSnapshot(this.export()); this.message = 'Snapshot exported. Session remains paused.';
                } catch (error) { this.message = `Export failed: ${error.message}`; }
            } else if (key === 'q') this.quit = true;
            return;
        }
        if (key === 'escape') { this.menu(); return; }
        if (this.screen === 'hint' || this.screen === 'help') {
            if (key === 'right' || key === 'down' || key === 'space' || key === 'pagedown') this.page++;
            else if (key === 'left' || key === 'up' || key === 'pageup') this.page = Math.max(0, this.page - 1);
            else if (key === 'enter' || key === 'h' || key === '?') this.screen = 'game';
            else if (key === 'x' && this.screen === 'hint') { this.session.dismissHint(); this.screen = 'game'; }
            return;
        }
        if (key === '?') { this.screen = 'help'; this.page = 0; return; }
        const view = this.session.view();
        if (key === 'p') { view.paused ? this.session.resume() : this.session.pause(); return; }
        if (view.paused) return;
        if (key === 'h') {
            // Reopening an active hint does not consume another hint.
            if (!view.state.activeHint) this.session.requestHint();
            if (this.session.view().state.activeHint) { this.screen = 'hint'; this.page = 0; }
            return;
        }
        if (key === 'x') { this.session.dismissHint(); return; }
        if (view.state.status === 'completed') return;
        if (['up', 'down', 'left', 'right', 'w', 'a', 's', 'd'].includes(key)) {
            const next = move(view.state.selectedCell, key); this.session.selectCell(next.row, next.col);
        } else if (/^[1-9]$/.test(key)) {
            if (!view.state.selectedCell) this.session.selectCell(0, 0);
            this.session.enterDigit(Number(key), view.assistLevel !== 'paper');
        } else if (['backspace', 'delete', '0'].includes(key)) this.session.erase();
        else if (key === 'n') this.session.toggleNotes();
        else if (key === 'u') this.session.undo();
    }
    render(columns = 80, rows = 24) {
        const limit = Math.max(1, Math.min(79, columns - 1));
        const finish = lines => lines.slice(0, Math.min(23, Math.max(1, rows))).map(l => ascii(l).slice(0, limit));
        if (columns < 80 || rows < 24) return finish(['DOKUEL - PAUSED', 'Please resize to at least 80 x 24.', 'P resumes after resizing. Q quits.']);
        if (this.screen === 'confirm') return finish([' DOKUEL / NEW PUZZLE', '', ` Replace current game with ${this.pending}?`, ' Export first from the menu if you want to keep this session.', '', ' Y: replace     N / Esc: cancel']);
        if (this.screen === 'menu') return finish([
            ' DOKUEL / MENU', ' ---------------------------------------------------------------', '',
            ' 1  Easy             2  Medium', ' 3  Hard             4  Expert', ' 5  Daily challenge  (locally seeded, Medium)', '',
            this.session ? ' R / Esc  Return to board (P to resume)' : ' Choose a puzzle to begin.',
            ' E        Export local snapshot (--snapshot FILE)', ' Q        Quit', '',
            ' New puzzles require confirmation while a session exists.',
            ' Notes: N toggles pencil mode; 1-9 add/remove the selected note.',
            ' H opens canonical hints. ? opens help from the board.', '',
            ...wrap(this.message, 74).map(l => ' ' + l),
        ]);
        const view = this.session.view();
        const { state, projection } = view;
        if (this.screen === 'hint' || this.screen === 'help') {
            const h = state.activeHint;
            const title = this.screen === 'help' ? 'HELP' : `HINT / ${h ? techniqueName(h.technique) : 'None'}`;
            const text = this.screen === 'help' ?
                'Arrows or WASD move. Digits enter values. N toggles NOTES mode: the same digit adds or removes a pencil note. Cells with notes show a colon; exact notes appear beside the board.\n\nGiven values have no suffix; your entries have +. Conflicts use ! marks and a count. The cursor uses brackets, with full status in the selected-cell panel. Hint-related cells are listed in the hint view.\n\nU undoes. 0, Backspace or Delete erase. H requests or reopens a canonical hint. Enter returns without dismissing it; X dismisses it. Hints explain moves but never enter a digit for you. Reveal is explicitly labeled.\n\nP pauses/resumes and hides the puzzle. Esc opens the paused menu. E in the menu exports to the explicitly supplied --snapshot file. Restore in a fresh process with --restore FILE. Q in the menu quits.\n\nActive thinking time includes reading hints/help. Menu, pause and undersized windows stop the shared clock. No automatic save or database exists.' :
                h ? `${h.explanation}\n\nTarget: ${coordinate(h.position.row, h.position.col)}; value: ${h.value}\nRelated cells: ${h.relatedCells.map(p => coordinate(p.row, p.col)).join(', ')}` : 'No active hint.';
            const lines = wrap(text, 74), pages = Math.max(1, Math.ceil(lines.length / 16));
            this.page = Math.min(this.page, pages - 1);
            return finish([` DOKUEL / ${title}`, ` Page ${this.page + 1}/${pages}`, '', ...lines.slice(this.page * 16, this.page * 16 + 16).map(l => ' ' + l), '', ' Left/Right: page  Enter: board  X: dismiss hint  Esc: menu']);
        }
        const pos = state.selectedCell ?? { row: 0, col: 0 }, selected = state.board[pos.row][pos.col];
        const errors = new Set([...projection.conflicts, ...projection.errors]);
        const identity = view.identity.kind === 'daily' ? `Daily ${view.identity.date}` : view.identity.kind === 'solo' ? 'Solo' : 'Imported puzzle';
        const lines = Array(23).fill('');
        lines[0] = ` DOKUEL / ${view.difficulty.toUpperCase()}                 ${elapsed(view.elapsedMs)}  ${view.paused ? 'PAUSED' : state.status === 'completed' ? 'COMPLETED!' : 'PLAYING'}`;
        lines[1] = ` ${identity}    ${projection.cellsRemaining} cells remaining`;
        lines[3] = '     1  2  3   4  5  6   7  8  9';
        const border = '   +---------+---------+---------+';
        let y = 4;
        for (let r = 0; r < 9; r++) {
            if (r % 3 === 0) lines[y++] = border;
            let row = ` ${String.fromCharCode(65 + r)} |`;
            for (let c = 0; c < 9; c++) {
                const item = state.board[r][c];
                const glyph = view.paused ? '?' : item.value ?? (item.notes.size ? ':' : '.');
                let token = ` ${glyph}${!view.paused && item.value && !item.isGiven ? '+' : ' '}`;
                if (!view.paused && errors.has(r * 9 + c)) token = `!${glyph}!`;
                if (!view.paused && r === pos.row && c === pos.col) token = `[${glyph}]`;
                row += token + (c % 3 === 2 ? '|' : '');
            }
            lines[y++] = row;
        }
        lines[y] = border;
        const panel = [
            `Selected: ${coordinate(pos.row, pos.col)}${state.selectedCell ? '' : ' (move to select)'}`,
            view.paused ? 'Puzzle hidden - P to resume' : `${selected.isGiven ? 'GIVEN' : 'EDITABLE'}${errors.has(pos.row * 9 + pos.col) ? ' / ! CONFLICT' : ''}`,
            `Mode: ${state.notesMode ? 'NOTES' : 'VALUE'}   N toggles`,
            view.paused ? 'Notes: hidden' : `Notes: ${[...selected.notes].sort().join(' ') || '(none)'}`,
            '', ...[0, 1, 2].map(r => '   ' + [1, 2, 3].map(c => view.paused ? '?' : selected.notes.has(r * 3 + c) ? r * 3 + c : '.').join(' ')),
            '', view.paused ? 'Conflicts: hidden' : `! Conflicts/errors: ${errors.size}`,
            `Undo: ${state.history.length}   Hints: ${state.hintsUsed}`,
            state.activeHint ? `Hint: ${techniqueName(state.activeHint.technique)}` : 'H: request a hint',
            state.activeHint ? 'H: details    X: dismiss' : '',
        ];
        for (let i = 0; i < panel.length; i++) lines[3 + i] = lines[3 + i].padEnd(38) + panel[i];
        lines[18] = ' [x] cursor  5 given  5+ entry  : notes  !x! conflict';
        lines[19] = ' Arrows/WASD move | 1-9 enter | N notes | 0/Del erase';
        lines[20] = ' U undo | H hint | P pause/resume | ? help | Esc menu';
        lines[22] = state.status === 'completed' ? ' PUZZLE COMPLETE! Esc: new puzzle / export / quit' : view.paused ? ' PAUSED - P to resume. Esc opens menu.' : ' Notes mode toggles notes; it never places a value.';
        return finish(lines);
    }
}
