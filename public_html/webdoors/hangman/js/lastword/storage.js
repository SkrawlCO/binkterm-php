/**
 * Last Word — WebDoor storage client (M1A foundation)
 *
 * REPAIR NOTE: the existing js/webdoor.js used by the current Hangman
 * gameplay calls `/api/webdoor/save`, `/api/webdoor/load`, and
 * `/api/webdoor/delete` — none of those routes exist. They 404, and
 * hangman.js's autoSave() swallows the failure (`.catch(() => {})`), so
 * Hangman's "per-user save/resume" has silently never worked. See
 * docs/WebDoors.md ("legacy save/load API calls are unchanged and still
 * currently return 404").
 *
 * The real, currently-implemented contract (src/WebDoorController.php,
 * routes/webdoor-routes.php) is:
 *
 *   GET    /api/webdoor/storage/{slot}?game_id=<id>   -> load
 *   PUT    /api/webdoor/storage/{slot}?game_id=<id>   -> save (body: {data, metadata})
 *   DELETE /api/webdoor/storage/{slot}?game_id=<id>   -> delete
 *
 * `game_id` is technically optional (the server falls back to parsing it out
 * of the HTTP Referer, e.g. ".../webdoors/hangman/index.html" -> "hangman"),
 * but this client passes it explicitly so storage calls do not depend on
 * Referer being present or well-formed.
 *
 * This module targets that real contract. It intentionally does NOT touch
 * js/webdoor.js or js/hangman.js — the currently-playable Hangman experience
 * is left exactly as it is (still not saving, but still playable) until an
 * intentional M1B+ activation point wires Last Word into index.html.
 *
 * Kept DOM-free and fetch-injectable so it can be exercised from a plain
 * Node test script without a browser or a live server.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LastWordStorage = factory();
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var GAME_ID = 'hangman'; // Manifest-visible naming is deferred; see M1A report.
    var SESSION_SLOT = 0;

    // Fork #7 ("Picking Sides"): a second, distinct slot for the tiny
    // lifetime SKIPPY-vs-BOB rivalry record (js/lastword/rivalry.js), kept
    // deliberately separate from SESSION_SLOT (0, the in-progress-session
    // resume save this module has always reserved but which the live
    // controller still does not wire up) so a future activation of session
    // resume can never collide with, or be overwritten by, rivalry writes.
    // Same game_id ('hangman') as everything else here: Last Word has no
    // manifest entry of its own yet, and game_id is a free-text column the
    // host keys storage rows by (not something manifest registration
    // gates), so reusing the identity this file already established for
    // Last Word is the smallest safe choice — inventing a new game_id here
    // would be unrelated scope, not a persistence requirement.
    var RIVALRY_SLOT = 1;

    /**
     * @param {Function} [fetchImpl] Injectable fetch (defaults to the global
     *   `fetch`, present in the WebDoor iframe's browser context).
     */
    function createLastWordStorage(fetchImpl) {
        var doFetch = fetchImpl || (typeof fetch !== 'undefined' ? fetch : null);
        if (!doFetch) {
            throw new Error('LastWordStorage: no fetch implementation available');
        }

        function storageUrl(slot) {
            return '/api/webdoor/storage/' + slot + '?game_id=' + encodeURIComponent(GAME_ID);
        }

        /**
         * Save the given session object to the reserved Last Word slot.
         * Returns the parsed JSON body on success.
         */
        function saveSession(session, slot) {
            slot = typeof slot === 'number' ? slot : SESSION_SLOT;
            var kind = slot === RIVALRY_SLOT ? 'lastword-rivalry' : 'lastword-session';
            return doFetch(storageUrl(slot), {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    data: session,
                    metadata: { kind: kind, stateVersion: session && session.stateVersion }
                })
            }).then(function (res) {
                if (!res.ok) {
                    throw new Error('LastWordStorage: save failed with HTTP ' + res.status);
                }
                return res.json();
            });
        }

        /**
         * Load the saved session, if any. Returns null on a 404 (no save
         * present yet) rather than throwing, since "no save" is an expected,
         * normal state for a new player.
         */
        function loadSession(slot) {
            slot = typeof slot === 'number' ? slot : SESSION_SLOT;
            return doFetch(storageUrl(slot), { method: 'GET' }).then(function (res) {
                if (res.status === 404) {
                    return null;
                }
                if (!res.ok) {
                    throw new Error('LastWordStorage: load failed with HTTP ' + res.status);
                }
                return res.json().then(function (body) {
                    return body && body.data !== undefined ? body.data : null;
                });
            });
        }

        /**
         * Delete the saved session (used on a completed/abandoned game reset).
         */
        function deleteSession(slot) {
            slot = typeof slot === 'number' ? slot : SESSION_SLOT;
            return doFetch(storageUrl(slot), { method: 'DELETE' }).then(function (res) {
                if (!res.ok) {
                    throw new Error('LastWordStorage: delete failed with HTTP ' + res.status);
                }
                return res.json();
            });
        }

        return {
            GAME_ID: GAME_ID,
            SESSION_SLOT: SESSION_SLOT,
            RIVALRY_SLOT: RIVALRY_SLOT,
            saveSession: saveSession,
            loadSession: loadSession,
            deleteSession: deleteSession
        };
    }

    return { createLastWordStorage: createLastWordStorage, RIVALRY_SLOT: RIVALRY_SLOT };
});
