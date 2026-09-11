const { OrdinaryPuzzlesSession, catalog } = require('../session.cjs');
const tiers = ['small', 'medium', 'large', 'extraordinary'];
function columnName(col) {
    let result = '';
    for (let n = col + 1; n; n = Math.floor((n - 1) / 26)) result = String.fromCharCode(65 + (n - 1) % 26) + result;
    return result;
}
class TerminalAdapter {
    constructor({ puzzleId = 'e9c2882a25e2', snapshot } = {}) {
        this.session = snapshot ? OrdinaryPuzzlesSession.restore(snapshot) : new OrdinaryPuzzlesSession(puzzleId);
        const i = this.session.state().interaction;
        this.cursor = (i.hovered || i.handler || '0:0').split(':').map(Number);
        this.offset = [0, 0]; this.menu = false; this.help = false; this.closed = false;
        this.tier = this.session.state().tier; this.choice = 0; this.idInput = null; this.message = '';
    }
    state() { return this.session.state(); }
    snapshot() { return this.session.export(); }
    load(id) {
        this.session.load(id); this.cursor = [0, 0]; this.offset = [0, 0];
        this.tier = this.state().tier; this.menu = false; this.message = '';
    }
    move(dr, dc) {
        const s = this.state();
        this.cursor = [Math.max(0, Math.min(s.rows - 1, this.cursor[0] + dr)), Math.max(0, Math.min(s.columns - 1, this.cursor[1] + dc))];
        // Same presentation routing as canonical onGridPointerMove: consult canonical hover.
        if (s.interaction.dragging && this.cursor.join(':') !== s.interaction.hovered) {
            if (s.interaction.hovered) this.session.leave(...s.interaction.hovered.split(':').map(Number));
            this.session.enter(...this.cursor);
        }
    }
    key(key) {
        if (this.closed) return;
        if (this.idInput !== null) {
            if (key === 'escape') this.idInput = null;
            else if (key === 'backspace') this.idInput = this.idInput.slice(0, -1);
            else if (key === 'return') {
                try { this.load(this.idInput); this.idInput = null; } catch (e) { this.message = e.message; }
            } else if (/^[a-f0-9]$/i.test(key) && this.idInput.length < 32) this.idInput += key.toLowerCase();
            return;
        }
        if (key === 'q' || key === 'ctrl-c') { this.closed = true; return; }
        if (key === '?') { this.help = !this.help; return; }
        if (this.help) { this.help = false; return; }
        if (key === 'n') { this.menu = !this.menu; this.choice = 0; return; }
        if (this.menu) {
            if (/^[1-4]$/.test(key)) { this.tier = tiers[Number(key) - 1]; this.choice = 0; }
            const records = catalog({ tier: this.tier });
            if (key === 'left' || key === 'a') this.choice = (this.choice + records.length - 1) % records.length;
            if (key === 'right' || key === 'd') this.choice = (this.choice + 1) % records.length;
            if (key === 'x') this.choice = Math.floor(Math.random() * records.length);
            if (key === 'i') { this.idInput = ''; this.message = ''; }
            if (key === 'return' || key === 'space') this.load(records[this.choice].puzzleId);
            if (key === 'escape') this.menu = false;
            return;
        }
        const movement = { up: [-1, 0], w: [-1, 0], down: [1, 0], s: [1, 0], left: [0, -1], a: [0, -1], right: [0, 1], d: [0, 1] }[key];
        if (movement) this.move(...movement);
        else if (key === 'space' || key === 'return') {
            if (this.state().interaction.dragging) this.session.end(...this.cursor);
            else this.session.begin(...this.cursor);
        } else if (key === 'r') this.session.tap(...this.cursor);
        else if (key === 'escape') this.session.exit();
    }
    render(width = 80, height = 24) {
        const s = this.state();
        const visibleRows = Math.max(1, Math.min(s.rows, Math.floor((height - 6) / 2)));
        const visibleCols = Math.max(1, Math.min(s.columns, Math.floor((width - 5) / 5)));
        for (const [axis, span, total] of [[0, visibleRows, s.rows], [1, visibleCols, s.columns]]) {
            this.offset[axis] = Math.max(0, Math.min(this.offset[axis], this.cursor[axis], total - span));
            if (this.cursor[axis] >= this.offset[axis] + span) this.offset[axis] = this.cursor[axis] - span + 1;
        }
        const [top, left] = this.offset;
        let lines = ['ORDINARY PUZZLES', `${s.name} | ${s.tier} | ${s.puzzleId} | ${s.rows}x${s.columns}`,
            `${s.cleared ? 'PUZZLE COMPLETE!' : `${s.lines.filter(l => l.completed).length}/${s.lines.length} lines complete`}  Cursor ${columnName(this.cursor[1])}${this.cursor[0] + 1}  ${s.interaction.dragging ? 'DRAWING' : 'Browse'}`];
        if (this.help) lines.push('', 'Extend a number into a straight line of that length.', 'Cover every dot. Canonical rules handle every request.', '', 'Arrows/WASD move; Space/Enter begins or ends a drag.', 'R taps current cell (origin reset); Esc releases drag.', 'N opens catalog; Q quits. No automatic save.', 'Brackets mark cursor; - and | mark occupied path.', 'Connections join cells belonging to the same line.', 'Any key closes help.');
        else if (this.menu || this.idInput !== null) {
            const record = catalog({ tier: this.tier })[this.choice];
            lines.push('', 'SELECT PUZZLE', '1 Small   2 Medium   3 Large   4 Extraordinary', '', `${this.tier}: ${this.choice + 1}/300`, `${record.name}  ${record.puzzleId}  ${record.rows}x${record.columns}`, '', 'Left/Right browse | X random | Enter load | I stable ID', 'Esc cancels | N returns | Q quits');
            if (this.idInput !== null) lines.push(`Puzzle ID: ${this.idInput}_`, this.message);
        } else {
            lines.push('    ' + Array.from({ length: visibleCols }, (_, c) => columnName(left + c).padStart(3).padEnd(5)).join(''));
            for (let r = top; r < top + visibleRows; r++) {
                let row = String(r + 1).padStart(3) + ' ';
                for (let c = left; c < left + visibleCols; c++) {
                    const cell = s.cells[r][c], selected = r === this.cursor[0] && c === this.cursor[1];
                    const symbol = cell.value.trim() || (cell.lineId ? (cell.orientation?.startsWith('vertical') ? '|' : '-') : ' ');
                    const joined = c + 1 < s.columns && cell.lineId && cell.lineId === s.cells[r][c + 1].lineId;
                    row += (selected ? '[' : ' ') + symbol.padStart(2).slice(-2) + (selected ? ']' : ' ') + (joined ? '-' : ' ');
                }
                lines.push(row);
                if (r + 1 < top + visibleRows) lines.push('    ' + Array.from({ length: visibleCols }, (_, n) => {
                    const c = left + n, cell = s.cells[r][c];
                    return cell.lineId && cell.lineId === s.cells[r + 1][c].lineId ? '  |  ' : '     ';
                }).join(''));
            }
            lines.push(`View rows ${top + 1}-${top + visibleRows}/${s.rows}, cols ${columnName(left)}-${columnName(left + visibleCols - 1)}/${columnName(s.columns - 1)}`,
                'Arrows/WASD move | Space/Enter begin/end | R tap/reset', 'N select | Esc release | ? help | Q quit (no automatic save)');
        }
        return lines.slice(0, height).map(line => line.slice(0, width).padEnd(width));
    }
}
module.exports = { TerminalAdapter, columnName };
