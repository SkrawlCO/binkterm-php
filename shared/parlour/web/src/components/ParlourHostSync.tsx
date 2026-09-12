'use client';

import { useEffect } from 'react';

/**
 * Production-only Web host wiring (Slice 5). `public_html/webdoors/parlour/
 * index.php` authenticates the caller, then redirects to this static app's
 * own basePath root with a one-time CSRF token in the query string — a
 * redirect, not an inline-served shell, because Next's client router needs
 * `location.pathname` to equal the basePath root exactly to hydrate (see
 * ../../README.md). This component claims that token once on mount, clears
 * it from the URL, and wires the "Save & Return" callback the launch iframe
 * looks for (`window.parlourHost.onReturn`) — replacing the old injected
 * `<head>` script (`public_html/webdoors/parlour/host.js`) that relied on
 * running before hydration, which does not apply once the shell is a
 * redirect instead of inline content.
 *
 * Local/dev use (no PP launch, no query param) leaves `window.parlourHost`
 * unset — the `/continue` persisted-session page only reads a CSRF token
 * when actually calling the storage API, so its absence there is harmless.
 */
declare global {
  interface Window {
    parlourHost?: { endpoint: string; csrfToken: string; onReturn?: () => void };
  }
}

export function ParlourHostSync() {
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const csrf = params.get('parlourCsrf');
    if (csrf) {
      window.parlourHost = { endpoint: '/webdoors/parlour/api.php', csrfToken: csrf };
      params.delete('parlourCsrf');
      const clean = window.location.pathname + (params.toString() ? `?${params}` : '') + window.location.hash;
      window.history.replaceState(null, '', clean);
    }
    if (window.parlourHost) {
      window.parlourHost.onReturn = () => {
        let target = '/experiences/parlour';
        try {
          const link = window.parent.document.getElementById('webdoor-return') as HTMLAnchorElement | null;
          if (link) {
            const url = new URL(link.href, window.location.origin);
            if (url.origin === window.location.origin) target = url.pathname + url.search + url.hash;
          }
        } catch {
          /* Standalone authenticated entry uses the direct Experience return. */
        }
        window.parent.location.assign(target);
      };
    }
  }, []);

  return null;
}
