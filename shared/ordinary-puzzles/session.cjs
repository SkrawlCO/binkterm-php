const { RootStore } = require('./runtime/board.cjs');
const { getPackRecords, packModes, packId, getPackRecordScore } = require('./runtime/pack.cjs');
const { revision: upstreamRevision } = require('./upstream.json');
const copy = value => JSON.parse(JSON.stringify(value));
const records = new Map();
for (const tier of packModes) {
    for (const record of getPackRecords(tier)) {
        if (records.has(record.id)) throw new Error('Duplicate canonical puzzle ID: ' + record.id);
        records.set(record.id, { tier, record });
    }
}
const metadata = ({ tier, record }) => ({
    puzzleId: record.id, name: record.name, type: 'puzzle', tier,
    rows: record.rows.length, columns: record.rows[0].length,
    rating: record.rating, score: getPackRecordScore(record), retired: Boolean(record.retired),
});
function catalog({ tier, includeRetired = false } = {}) {
    if (tier !== undefined && !packModes.includes(tier)) throw new RangeError('Unknown tier');
    return [...records.values()].filter(entry => (tier === undefined || entry.tier === tier)
        && (includeRetired || !entry.record.retired)).map(metadata);
}
// Structural equality tolerates object-key reordering by JSON storage/transport.
function equal(a, b) {
    if (a === b) return true;
    if (!a || !b || typeof a !== 'object' || typeof b !== 'object' || Array.isArray(a) !== Array.isArray(b)) return false;
    const keys = Object.keys(a);
    return keys.length === Object.keys(b).length && keys.every(key => Object.hasOwn(b, key) && equal(a[key], b[key]));
}

/** Catalog/input/state projection only. Every puzzle transition belongs to RootStore. */
class OrdinaryPuzzlesSession {
    #root;
    #entry;
    #actions = [];
    constructor(puzzleId) {
        this.#root = new RootStore();
        this.load(puzzleId);
    }
    load(puzzleId) {
        const entry = records.get(puzzleId);
        if (!entry) throw new RangeError('Unknown puzzle ID');
        this.#root.interactions.disableInteractions();
        this.#root.board.initialize(puzzleId, entry.record.rows);
        // Unit-cell geometry enables canonical pointer lifecycle without screen pixels.
        this.#root.interactions.enableInteraction({ nativeEvent: { layout: {
            x: 0, y: 0, width: this.#root.board.colsCount, height: this.#root.board.rowsCount,
        } } });
        this.#entry = entry; this.#actions = [];
        return this.state();
    }
    #apply(type, row, col) {
        const interactions = this.#root.interactions;
        if (type === 'exit') {
            interactions.onGridTouchExit();
            this.#actions.push({ type });
        } else {
            // Transport validation only; do not filter occupied/origin/invalid moves.
            if (!Number.isInteger(row) || !Number.isInteger(col) || row < 0 || col < 0
                || row >= this.#root.board.rowsCount || col >= this.#root.board.colsCount) {
                throw new RangeError('Cell coordinate outside board');
            }
            const cell = this.#root.board.at(row, col);
            const handler = { begin: 'onCellTouch', enter: 'onCellEnter', leave: 'onCellLeave', end: 'onCellTouchEnd' }[type];
            if (!handler) throw new TypeError('Unknown interaction');
            interactions[handler](cell);
            this.#actions.push({ type, cell: [row, col] });
        }
        return this.state();
    }
    begin(row, col) { return this.#apply('begin', row, col); }
    enter(row, col) { return this.#apply('enter', row, col); }
    leave(row, col) { return this.#apply('leave', row, col); }
    end(row, col) { return this.#apply('end', row, col); }
    exit() { return this.#apply('exit'); }
    tap(row, col) { this.begin(row, col); return this.end(row, col); }
    state() {
        const { board, interactions: i } = this.#root;
        return {
            ...metadata(this.#entry), cleared: board.cleared,
            cells: board.grid.map(row => row.map(c => ({
                id: c.id, row: c.row, col: c.col, value: c.value,
                lineId: c.line?.id ?? null, filled: c.filled, valid: c.valid,
                completed: c.completed, orientation: c.orientation ?? null,
                hovered: c.hovered, highlighted: Boolean(c.highlighted),
            }))),
            lines: board.lines.map(l => ({
                id: l.id, origin: l.origin.id, clue: l.origin.value,
                cells: l.cells.map(c => c.id), committed: l.committedCells.map(c => c.id),
                pending: l.pendingCells.map(c => c.id), stale: l.stale,
                handler: l.currentHandler?.id ?? null, orientation: l.orientation,
                valid: l.valid, completed: l.completed,
            })),
            interaction: {
                enabled: Boolean(i.gridLayout), dragging: i.isDragging,
                handler: i.currentHandler?.id ?? null, lineId: i.draggedLine?.id ?? null,
                hovered: i.hoveredCell?.id ?? null, moves: i.numberOfMoves,
            },
        };
    }
    export() {
        return { schemaVersion: 1, upstreamRevision, packId, puzzleId: this.#entry.record.id,
            actions: copy(this.#actions), verification: this.state() };
    }
    static restore(snapshot) {
        if (!snapshot || snapshot.schemaVersion !== 1 || snapshot.upstreamRevision !== upstreamRevision
            || snapshot.packId !== packId || !Array.isArray(snapshot.actions)) throw new TypeError('Unsupported snapshot');
        const session = new OrdinaryPuzzlesSession(snapshot.puzzleId);
        for (const action of snapshot.actions) {
            if (!action || typeof action !== 'object') throw new TypeError('Invalid action');
            if (action.type === 'exit' && Object.keys(action).length === 1) session.exit();
            else if (['begin', 'enter', 'leave', 'end'].includes(action.type)
                && Object.keys(action).length === 2 && Array.isArray(action.cell) && action.cell.length === 2) {
                session.#apply(action.type, ...action.cell);
            } else throw new TypeError('Invalid action');
        }
        if (!equal(session.state(), snapshot.verification)) throw new Error('Snapshot replay verification failed');
        return session;
    }
}
module.exports = { OrdinaryPuzzlesSession, catalog, packId, upstreamRevision };
