/*
 * L33TEST Crossroads bootstrap for the Chessmata WebDoor
 * (Experience #4, graphical Web surface).
 *
 * Runs in the WebDoor iframe (served by index.php). It:
 *
 *   1. clears any stale Chessmata token in this browser (shared-computer
 *      hygiene: a previous BinkTerm user must not linger as the SPA identity);
 *   2. does a same-origin POST to web-credential.php for a short-lived Chessmata
 *      JWT for THIS authenticated caller's account;
 *   3. writes it into the OFFICIAL upstream SPA's own localStorage key
 *      (chessmata_auth_token) -- same origin, so the SPA at /chessmata/ reads it
 *      on mount and comes up already logged in;
 *   4. joins the normal BinkTermPHP WebDoor session lifecycle (Crossroads
 *      presence: Live Now / Your Places / roster);
 *   5. replaces this document with the SPA (/chessmata/).
 *
 * The token is never put in a URL, never logged, and exists only in the
 * mechanism the upstream client already uses. This file adds no chess UI.
 */
(function () {
  'use strict';

  var GAME_ID = 'chessmata-web';
  var statusEl = document.getElementById('status');

  function fail(msg) {
    if (!statusEl) {
      return;
    }
    statusEl.innerHTML =
      '<p class="err">' + msg + '</p>' +
      '<p><a href="javascript:location.reload()">Try again</a></p>';
  }

  // (4) WebDoor session lifecycle -- best effort, must not block entry.
  function joinSession() {
    try {
      fetch('/api/webdoor/session?game_id=' + encodeURIComponent(GAME_ID), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).catch(function () {});
    } catch (e) { /* fetch unavailable */ }
  }

  // (1) stale-token hygiene -- remove before we know the new one.
  try { localStorage.removeItem('chessmata_auth_token'); } catch (e) {}

  // (2) same-origin POST for the hand-off.
  fetch('web-credential.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json' },
    // no body; identity comes from the BinkTerm session cookie
  })
    .then(function (r) {
      if (!r.ok) {
        return r.json().catch(function () { return {}; }).then(function (j) {
          throw new Error(j && j.error ? j.error : ('HTTP ' + r.status));
        });
      }
      return r.json();
    })
    .then(function (data) {
      var key = (data && data.storage_key) || 'chessmata_auth_token';
      var path = (data && data.client_path) || '/chessmata/';
      if (!data || !data.access_token) {
        throw new Error('no token in hand-off response');
      }
      // (3) seed the upstream SPA's own auth store (same origin as /chessmata/).
      try {
        localStorage.setItem(key, data.access_token);
      } catch (e) {
        throw new Error('this browser is blocking local storage');
      }
      joinSession();
      // (5) enter the real SPA. replace() so the loader is not in iframe history.
      window.location.replace(path);
    })
    .catch(function (err) {
      fail('Could not connect you to Chessmata (' + (err && err.message ? err.message : 'unknown error') + ').');
    });
})();
