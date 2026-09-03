/*
 * L33TEST Crossroads glue for the OpenGlad WebDoor (M4 Slice 1A/1E).
 *
 * Client-side integration between BinkTermPHP and the pinned OpenGlad Web/WASM
 * build. It:
 *
 *   1. points OpenGlad's already-shipped relay override
 *      (window.__opengladRelayBaseUrlForTests, read by
 *      relay_base_url_or_default on the emscripten path) at the L33TEST
 *      same-origin self-hosted relay, so multiplayer never touches the
 *      upstream openglad.pages.dev relay;
 *   2. joins the normal BinkTermPHP WebDoor session lifecycle via
 *      GET /api/webdoor/session, which creates/reuses the webdoor_sessions
 *      row (Crossroads presence: Live Now / Your Places / lobby roster) and
 *      records the webdoor_play footprint. The end-of-participation beacon is
 *      fired by the outer host page (templates/webdoor_play.twig) on unload.
 *
 * It does NOT modify OpenGlad, touch IndexedDB, or select a direct-connect
 * transport. Per-user persistence isolation is provided by the OpenGlad carried
 * patch (window.__opengladPersistNamespace, pending openglad/openglad#281),
 * which the server-side entry point (index.php) sets as a literal in <head>
 * BEFORE this script runs -- the token is not this file's concern.
 *
 * index.php injects, right after <head>:
 *   <script>window.__opengladPersistNamespace="<token>";</script>
 *   <script src="crossroads-glue.js"></script>
 */
(function () {
  'use strict';

  var RELAY_PATH = '/openglad-relay';
  var GAME_ID = 'openglad';

  // 1. Same-origin self-hosted relay. Absolute so it is unambiguous inside the
  //    WebDoor iframe regardless of how OpenGlad resolves relative URLs.
  window.__opengladRelayBaseUrlForTests = window.location.origin + RELAY_PATH;

  // 2. WebDoor session lifecycle (presence + resume marker + footprint).
  try {
    fetch('/api/webdoor/session?game_id=' + encodeURIComponent(GAME_ID), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    })
      .then(function (response) {
        return response && response.ok ? response.json() : null;
      })
      .then(function (session) {
        if (session && session.session_id) {
          window.__crossroadsWebDoorSession = {
            id: session.session_id,
            user: session.user || null,
            expires_at: session.expires_at || null
          };
        }
      })
      .catch(function () {
        // Presence is best-effort; a failed session call must not break the
        // game. An admin who somehow reaches this without catalog membership
        // gets a 404 here and simply has no Crossroads presence row.
      });
  } catch (e) {
    /* fetch unavailable - nothing to do */
  }
})();
