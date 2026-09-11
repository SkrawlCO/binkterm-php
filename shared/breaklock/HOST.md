# BreakLock host packaging

Pinned upstream and MIT notices remain in upstream.json and LICENSE.upstream.
Canonical Pattern and shared orchestration remain under shared/breaklock; the Web
bundle imports that code and the NativeDoor launcher imports terminal/play.js.
The custom dot-pattern SVG icon is original host presentation artwork.

Rebuild with pinned tooling:

```
cd shared/breaklock/web
npm ci --ignore-scripts
node build.mjs ../../../public_html/webdoors/breaklock/assets
```

Commit the resulting assets along with source changes. The shell and API require
project authentication, and API POSTs require the normal session CSRF token. No
caller ID is accepted in requests. The native manifest supplies DOOR_USER_NUMBER
from the authenticated launcher and clears inherited environment variables.
Both surfaces use namespace breaklock, slot 0. No daemon or schema migration.

Enablement uses the existing WebDoor and NativeDoor settings. BreakLock is PP's
sixth member; existing members retain their order. The build registers no service
worker. The host service worker caches static assets only; API responses are POST
and no-store. The shared persistence README explains abrupt-disconnect limits.

The pre-activation commit leaves both surfaces disabled, providing a safe rollback
boundary. Activation changes only their two enabled settings after checks pass.
Disable both settings to remove BreakLock from discovery without disturbing saves
or any other PP member.
