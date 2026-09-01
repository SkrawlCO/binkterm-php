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

**Logging note:** upstream `multizorkd`'s `process_connection_command()`
logs every raw line of client input before dispatch. Slice 3 kept that
log out of the casually-browsed `docker logs binkterm-app` stream by
pointing it at the dedicated `/var/lib/multizork/log/` files (mode `700`,
still readable by anyone who can `docker exec`/read the container FS).

Two local source patches now keep every credential out of that log:

- **`0002`** redacts the credential-bearing *input* lines — a returning
  player's **access code** (`inpfn_hello_sailor`, the credential
  `MultiZorkAdapter` stores and invisibly re-submits) and a **join code**
  (`inpfn_enter_instance_code_to_join`) — to `New input from socket N:
  <redacted>`.
- **`0003`** redacts the per-game **instance/join code** (`inst->hash`) in
  the eight instance-lifecycle `loginfo` statements (`Created new
  instance`, `Saving instance`, `Destroying instance`, `Rehydrated
  archived instance`, the DB-consistency warning, the endgame line, the
  not-a-player warning, and the Z-machine crash line) — each now logs the
  instance as `'<redacted>'` while keeping the event and all non-secret
  fields. On a crash the players in that instance still receive the full
  text (with the hash) via the game broadcast; only the log copy is
  redacted.

All other logging is unchanged. See
[`multizork-backend/`](multizork-backend/README.md) for the patches,
build/provenance record and regression harness. These patches are **live in
production as of 2026-09-01** (binary `multizorkd.p3`, `sha256
4f1d780c…88a9ef92`) — verified with a live Crossroads smoke: a real caller
launch, returning-access-code auto-submission, ordinary gameplay, and the
restricted daemon log showing `<redacted>` for every credential-bearing
input and instance-lifecycle line with no credential value present anywhere
in it.

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

The `multizorkd` binary is the pinned upstream `icculus/mojozork` commit
`f94c3104aa18036d9ed5f0243814483f82e486cb` (daemon version `0.0.9`),
built with **`gcc -O2 -DNDEBUG -Wall -o multizorkd multizorkd.c -lsqlite3`**
in a disposable Ubuntu 22.04 container (`gcc 11.4.0`, `libsqlite3-dev
3.37.2`). (Earlier revisions of this document said `gcc -O2 -Wall`; the
`-DNDEBUG` was omitted in error — the prior binary's `.rodata`/size and a
byte-exact rebuild both confirm it was built with `-DNDEBUG`.)

It carries three disclosed, non-gameplay patches, **all live in production
as of 2026-09-01**:

- **`0001`** — the daemon has no CLI flag to choose a bind address, so
  `prep_listen_socket()`'s `getaddrinfo(NULL, ...)` (every interface)
  becomes `getaddrinfo("::1", ...)` (loopback only), verified unreachable
  via `127.0.0.1`/`0.0.0.0`. (This was the only patch in the pre-2026-09-01
  binary `sha256 6dcdde18…5536fc89`.)
- **`0002`** / **`0003`** — credential-log redaction (see the **Logging
  note** above).

Deployed binary: `sha256
4f1d780cf0ea98061ceaebb5ae4321907edb4eede7f3d3cec84530ba88a9ef92`, GNU
build-id `3ac91e0762cb37215e6dbc9292407192f5217d2b`. Instruction-for-
instruction it equals the prior binary except the redacted `loginfo`
statements (`build/verify-equivalence.sh`). The prior binary is retained
in-container as `/var/lib/multizork/bin/multizorkd.6dcdde18.bak` for a
binary-only rollback (temporary — remove once the new binary has soaked).

The exact pinned source, the patch files, the containerised build recipe,
a binary-equivalence check and a black-box regression harness are kept in
[`multizork-backend/`](multizork-backend/README.md) — the local
modifications were previously never retained as patch files, which this
directory corrects. The upstream repository is deliberately not vendored.

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

### Web and Telnet convergence (Slice 4)

Native `terminal_mode: "line"` Experiences now use the existing authenticated
managed-door xterm/WebSocket/session path on the web. The bridge selects a
generic line adapter, which starts `scripts/line-relay-runtime.php`; that PHP
runtime connects the same private endpoint and invokes the same optional PHP
adapter contract used by the terminal server. MultiZork therefore remains one
native Experience backed by one `[::1]:43023` daemon and one persistent state
database. No web-specific backend, credential mapping, or expedition exists.

The approved icon is stored beside the production manifest as
`native-doors/doors/multizork/icon.png` and is served through the existing
manifest-declared managed-door asset route.

Production acceptance proved a real web caller and Telnet caller concurrently
sharing the same expedition and room, mutual action/chat visibility, and
distinct persistent identities. Browser refresh reattached to the same managed
runtime. Explicit **End Session** bypassed reconnect grace, waited for the
bridge-owned runtime to exit and its backend socket to close, and permitted an
immediate clean relaunch with the stored credential. The disposable Slice 1–4
proof accounts, mappings, scratch files, and sensitive daemon log contents were
removed after acceptance; production player state and legitimate session
history were retained.

> **OPERATIONS FOLLOW-UP / DEFERRED ITEMS (as of 2026-09-01):**
>
> - `/var/lib/multizork` still lives on the `binkterm-app` container writable
>   layer. **Recreating that container destroys the production player database
>   and the deployed binary + `supervisord.conf` edit** — not safe until the
>   directory is moved to durable managed storage with verified backup/restore.
> - Credential-log redaction (`0002` + `0003`) is **deployed and verified** —
>   no returning access code and no instance/join code reaches the daemon log.
> - **Not remediated this session:** credential-shaped tokens in *historical*
>   log lines written *before* the 2026-09-01 deploy — both in
>   `/var/lib/multizork/log/multizorkd.out.log` and in the pre-Slice-3 shared
>   `docker logs binkterm-app` JSON stream. Left as-is (not truncated).
> - **By design, not a log:** on a Z-machine crash the instance hash still
>   reaches the affected players' screens + their `transcripts` row, and the
>   transcript web view is `…/game/<hash>`. These are hash-addressed
>   gameplay-recap surfaces, out of scope for log redaction.
> - The prior binary is kept as `multizorkd.6dcdde18.bak` for rollback —
>   remove after the new binary has soaked.

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
