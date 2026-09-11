/* L33TEST input/containment only. All state, moves, rules and completion are upstream. */
'use strict';
history.replaceState(null, '', '#' + document.querySelector('meta[name="tatham-puzzle-id"]').content);
document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('puzzlecanvas');
    let secondary = false;
    // Reuse upstream control descriptions rather than invent another help system.
    const instructions = [...document.querySelectorAll('p')].map(p => p.textContent).find(t => t.includes('Click on a square')) || '';
    const controls = document.createElement('div');
    controls.id = 'tatham-touch';
    const buttons = ['○', '×'].map((glyph, index) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.id = index ? 'tatham-mark' : 'tatham-bulb';
        button.textContent = glyph;
        button.setAttribute('aria-label', instructions.split('.').filter(s => s.trim())[index]?.trim() || glyph);
        button.setAttribute('aria-pressed', String(index === 0));
        button.addEventListener('click', () => {
            secondary = index === 1;
            buttons.forEach((b, i) => b.setAttribute('aria-pressed', String(i === index)));
            canvas.focus({preventScroll: true});
        });
        controls.appendChild(button);
        return button;
    });
    canvas.parentElement.before(controls);
    let pointer = null;
    canvas.addEventListener('pointerdown', event => {
        if (event.pointerType !== 'touch') return;
        event.preventDefault();
        event.stopImmediatePropagation();
        pointer = event.pointerId;
        canvas.setPointerCapture(pointer);
    }, true);
    canvas.addEventListener('pointerup', event => {
        if (event.pointerType !== 'touch') return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (event.pointerId !== pointer) return;
        pointer = null;
        const box = canvas.getBoundingClientRect();
        if (event.clientX < box.left || event.clientY < box.top ||
            event.clientX >= box.right || event.clientY >= box.bottom) return;
        // Reuse upstream's coordinate conversion, including its HiDPI scale.
        const {x, y} = canvas_mouse_coords(event, canvas);
        const button = secondary ? 2 : 0;
        Module._mousedown(x, y, button);
        Module._mouseup(x, y, button);
    }, true);
    canvas.addEventListener('pointercancel', () => { pointer = null; });
});

// Lease secrets live only in this page's memory. Refresh waits for expiry.
(() => {
    let ready = false, lease = null, lastSaved = null, pending = null, stopped = false;
    const labels = JSON.parse(document.querySelector('meta[name="tatham-labels"]').content);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
    let panel, status;
    const gate = event => {
        if (!ready && !event.target.closest?.('#tatham-progress')) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    };
    for (const type of ['click', 'pointerdown', 'pointerup', 'mousedown', 'mouseup', 'keydown', 'touchstart', 'touchend']) {
        document.addEventListener(type, gate, {capture: true, passive: false});
    }
    function show(text) { status.textContent = text; }
    function fail(error) {
        stopped = true;
        ready = false;
        show(error.reason === 'conflict' ? labels.conflict : labels.error);
        panel.dataset.state = 'blocked';
    }
    async function request(action, extra = {}) {
        const response = await fetch('api.php', {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
            body: JSON.stringify({action, owner_token: lease?.owner_token, ...extra}),
            signal: AbortSignal.timeout(5000)
        });
        const data = await response.json();
        if (!response.ok || !data.success) throw data;
        return data;
    }
    function serialize() {
        const ptr = Module._get_save_file();
        try { return UTF8ToString(ptr); }
        finally { Module._free_save_file(ptr); }
    }
    function deserialize(payload) {
        const bytes = new TextEncoder().encode(payload);
        let pos = 0;
        savefile_read_callback = (buf, len) => {
            if (pos + len > bytes.length) return false;
            writeArrayToMemory(bytes.subarray(pos, pos + len), buf);
            pos += len;
            return true;
        };
        try { Module._load_game(); }
        finally { savefile_read_callback = null; }
        if (serialize() !== payload) throw new Error('Canonical load mismatch');
    }
    async function checkpoint() {
        if (pending) return pending;
        if (stopped || !lease) throw new Error('No active lease');
        pending = (async () => {
            const payload = serialize();
            if (payload !== lastSaved) {
                show(labels.saving);
                const result = await request('save', {attempt_id: lease.attempt_id, revision: lease.revision, payload});
                if (result.data.payload !== payload) throw new Error('Canonical acknowledgment mismatch');
                lease.revision = result.revision;
                lastSaved = payload;
            }
            panel.dataset.revision = String(lease.revision);
            panel.dataset.state = 'saved';
            show(labels.saved + ' (' + lease.revision + ')');
        })().catch(error => { fail(error); throw error; }).finally(() => { pending = null; });
        return pending;
    }
    document.addEventListener('DOMContentLoaded', async () => {
        panel = document.createElement('div');
        panel.id = 'tatham-progress';
        status = document.createElement('span');
        status.setAttribute('role', 'status');
        panel.appendChild(status);
        for (const returning of [false, true]) {
            const button = document.createElement('button');
            button.id = returning ? 'tatham-save-return' : 'tatham-save';
            button.textContent = labels.save + (returning ? ' & ' + labels.return : '');
            button.onclick = async () => {
                if (!lease || stopped) return;
                try {
                    ready = false;
                    if (pending) await pending;
                    await checkpoint();
                    ready = true;
                    if (returning) {
                        ready = false;
                        stopped = true;
                        await request('release');
                        parent.location.assign('/experiences/tatham');
                    }
                } catch (error) { fail(error); }
            };
            panel.appendChild(button);
        }
        document.body.prepend(panel);
        show(labels.loading);
        try {
            for (let i = 0; !globalThis.Module?._get_save_file || !document.getElementById('permalink-desc')?.getAttribute('href'); i++) {
                if (i > 200) throw new Error('Engine unavailable');
                await delay(50);
            }
            // Keep canonical import/export dialogs out of the authoritative save flow.
            for (const id of ['save', 'load']) {
                const control = document.getElementById(id);
                if (control) control.hidden = true;
            }
            for (let i = 0; ; i++) {
                try { lease = await request('acquire'); break; }
                catch (error) {
                    if (error.reason !== 'conflict' || i >= 16) throw error;
                    show(labels.conflict);
                    await delay(2000);
                }
            }
            if (lease.data.payload) {
                deserialize(lease.data.payload);
                lastSaved = lease.data.payload;
            }
            await checkpoint();
            ready = true;
            setInterval(() => {
                if (!stopped && !pending) checkpoint().catch(() => {});
            }, 1000);
            setInterval(() => {
                if (!stopped && !pending) request('renew').catch(fail);
            }, 10000);
        } catch (error) { fail(error); }
    });
})();
