# MultiZork production deployment (Crossroads Experience #1, Slice 3)

This document covers the production deployment of the MultiZork Experience:
the durable backend service, its runtime artifacts, and how another SysOp
would reproduce this deployment. For the reusable line-relay capability and
the L33TEST-owned adapter design, see
[MultiZorkSlice1.md](MultiZorkSlice1.md) — that architecture is unchanged by
productionization.

## Service lifecycle

`binkterm-app`'s container already uses `supervisord`
(`/etc/supervisor/conf.d/supervisord.conf`) to run every long-lived
companion process (`telnet`, `binkp_server`, `dosdoor_bridge`, etc.), with
`autostart=true`/`autorestart=true`. `multizorkd` is registered the same
way, as `[program:multizorkd]`, so it starts when the container starts and
restarts automatically if it ever exits unexpectedly — no new
infrastructure mechanism was introduced.

This mirrors the existing `dosdoor_bridge` block's own precedent for a
similar persistent-world game backend (the M4E "Elsewhere" pwmangband
adapter): the `[program:multizorkd]` block is a live edit to the running
container's `supervisord.conf`, **not baked into the Docker image**. It
survives an ordinary `docker restart` of the same container (verified —
see the Slice 3 report) because that file lives on the container's own
writable layer, not the bind-mounted app directory. It would **not**
survive the container being recreated (`docker compose up
--force-recreate`, or an image rebuild) without re-applying the same
`supervisord.conf` change (and re-running the artifact acquisition steps
below) — a deliberate, disclosed limitation matching the existing
Elsewhere precedent, not an oversight. Baking this into the image (a
`Dockerfile`/compose-level change) is a reasonable later step but was not
required to satisfy Slice 3's proof and was not made.

Operational commands (run inside the `binkterm-app` container):

```
supervisorctl status multizorkd        # check state
supervisorctl restart multizorkd       # restart just the MultiZork backend
supervisorctl tail multizorkd          # recent stdout
```

## Durable artifact locations

Everything lives under `/var/lib/multizork/` inside the container —
deliberately outside `/var/www/html` (the bind-mounted git repository), so
none of it is ever at risk of being confused with, or committed to,
tracked source:

| Path | Contents | Ownership/mode |
|---|---|---|
| `/var/lib/multizork/bin/multizorkd` | the compiled daemon binary | `multizork:multizork`, `555` (read+execute only) |
| `/var/lib/multizork/story/zork1-r88.dat` | the Route A R88 story artifact | `multizork:multizork`, `444` (read only) |
| `/var/lib/multizork/state/multizork.sqlite3` | mutable expedition/player state | `multizork:multizork`, writable |
| `/var/lib/multizork/log/multizorkd.{out,err}.log` | daemon's own stdout/stderr | `multizork:multizork`, `700` directory |

A dedicated, unprivileged service account (`multizork`, not `www-data`)
owns all of it and runs the daemon — least-privilege, and distinct from
the web server's own account.

**Logging note:** multizorkd's own upstream logging (unmodified, not a
BinkTermPHP concern) writes every raw line of client input to its log,
including a player's access code when it is submitted — whether typed by
a human or submitted invisibly by `MultiZorkAdapter`. Left on
supervisord's default `stdout`/`stderr` targets, that would land in the
same shared stream `docker logs binkterm-app` shows for every service.
Slice 3 deliberately points `multizorkd`'s log destination at the
dedicated `/var/lib/multizork/log/` files instead, keeping it out of that
casually-browsed shared stream (still readable by whoever can already
`docker exec`/read the container's filesystem — this narrows exposure, it
does not eliminate the fact that multizorkd itself logs the value; see
the Slice 3 report's security audit for the full account, including what
was already written to the shared log stream before this fix).

## Story artifact provenance (unchanged from Slice 1/Gate 1C)

The production artifact is the same, already-approved Route A binary — no
new provenance question was opened:

- Repository: `https://github.com/historicalsource/zork1`
- Pinned commit: `34cc828c4fa3b5e2581ea24c43bb8acb386d25d0` ("Revision 88
  (Original Source)")
- Path: `zork1.zip` (a raw Z-machine v3 story despite the extension — see
  Gate 1C)
- SHA-256: `158f1f63b1302591bbce30e1ec23b17909d2a66e39403ed14593a75280b1e7f9`
- Header: version 3, release 88, serial 840726, checksum `0xa129`, object
  table `0x02b0`, globals `0x2271`, static memory `0x2e53`

Reproducible acquisition (used verbatim for the production copy):

```
git clone https://github.com/historicalsource/zork1.git
cd zork1
git show 34cc828c4fa3b5e2581ea24c43bb8acb386d25d0:zork1.zip > zork1-r88.dat
sha256sum zork1-r88.dat   # must equal the hash above
```

The `multizorkd` binary itself is the pinned upstream
`icculus/mojozork` commit `f94c3104aa18036d9ed5f0243814483f82e486cb`,
built with `gcc -O2 -Wall -o multizorkd multizorkd.c -lsqlite3`, with
**one disclosed, non-gameplay patch**: the daemon has no CLI flag to
choose a bind address, so `prep_listen_socket()`'s `getaddrinfo(NULL, ...)`
(which binds every interface) is changed to `getaddrinfo("::1", ...)`
(loopback only). This is the same patch used throughout the disposable
runtime proof and Slices 1–2; it is a deployment/network-binding change,
not a change to Zork/MultiZork gameplay behavior, and it means multizorkd
only ever accepts connections from `::1` inside the container — verified
directly (Slice 3 report) to be unreachable via `127.0.0.1` or `0.0.0.0`.
Rebuilding this binary from source was not required for Slice 3, since
the already-verified binary from the disposable proof (identical
`sha256`) was redeployed; building it inside the container's own
toolchain (which would need `gcc`/`libsqlite3-dev` added to the image) is
a reasonable later hardening step, not required by this slice.

## Experience manifest and configuration

- `native-doors/doors/multizork/nativedoor.json` — the production manifest
  (`terminal_mode: "line"`, `relay_host: "::1"`, `relay_port: 43023`,
  `relay_adapter_class: "BinktermPHP\\Crossroads\\MultiZorkAdapter"`,
  `max_nodes: 4` — MultiZork's own proven simultaneous-player cap, not a
  larger claimed number — `requirements.admin_only: false`).
- `config/nativedoors.json` (site-local, gitignored, like `binkp.json`) —
  carries the `"multizork"` entry (`enabled: true`,
  `max_concurrent_sessions: 4`, `allow_anonymous: false`, ordinary
  authenticated callers only) that another SysOp would normally set
  through **Admin → Native Doors** rather than by hand-editing this file.
  The earlier Slice 1/2 test-only manifest (`multizork-slice1-test`)
  remains present but `enabled: false` — kept, not deleted, so the
  disposable test path stays available without reconstruction, but it no
  longer appears in any catalog.

### Why the web surface shows "unavailable", not hidden

`terminal_mode: "line"` doors have no web launch path today (the generic
line-relay only exists in the telnet/SSH terminal stack). `GameCatalog`
now reports `surfaces.web = 'unavailable'` for any such door (a small,
generic, reusable change — not MultiZork-specific), truthfully, rather
than either claiming `full` (which would be false — there is nothing to
launch) or hiding the Experience from web discovery entirely via
`hide_from_web` (a distinct, stronger, operator-controlled decision this
slice did not need). A caller browsing Crossroads on the web sees
MultiZork listed with an honest "not available on this surface" state.

## Identity/expedition model (unchanged)

Still exactly the model proven in Slices 1–2: one fixed expedition
(`MultiZorkAdapter::FIXED_EXPEDITION_ID`, renamed from the Slice 1/2
test-only value `multizork-slice1-test` to `multizork-prime` when
productionized — old test-era credential rows under the old id were left
orphaned, not migrated, since they belonged only to disposable test
accounts), no multi-expedition browsing, invitations, or lobby
orchestration. A caller creates or joins through MultiZork's own native
prompts the first time; Crossroads captures and, on every later launch,
invisibly resubmits their private return credential.
