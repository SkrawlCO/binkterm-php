# ascii-royale production deployment (Crossroads Experience #2, M4)

This document covers the durable production runtime for the ascii-royale
Experience: the managed shared arena, its runtime artifacts, how another
SysOp reproduces the deployment, and how the Experience is opened to ordinary
authenticated users on Web and Telnet.

The upstream pin, the (absence of) source patches, the build recipe and the
wrapper regression harness are in
[`ascii-royale-backend/`](ascii-royale-backend/README.md).

> **Slice 1** made the arena a durable, supervisor-managed service and proved
> the committed launcher accepts its record. The launcher
> (`native-doors/doors/ascii-royale-m3/launch-ascii-royale.sh`) and the
> managed-arena runtime are **unchanged** by everything below.
>
> **Slice 2** opens the Experience to ordinary authenticated users on Web and
> Telnet — a two-line tracked manifest policy change plus the site-local
> enable switch. See [Ordinary-user + Telnet enablement](#ordinary-user--telnet-enablement-m4-slice-2).
>
> **Slice 3** ships the custom Experience icon — presentation only, no
> mechanism change. See [Experience icon](#experience-icon-m4-slice-3). This
> closes M4.

---

## Service lifecycle

`binkterm-app`'s container already uses `supervisord`
(`/etc/supervisor/conf.d/supervisord.conf`) to run every long-lived companion
process (`caddy`, `php-fpm`, `binkp_server`, `dosdoor_bridge`, `telnet`,
`multizorkd`, …) with `autostart=true`/`autorestart=true`. The ascii-royale
arena is registered the same way, as **`[program:ascii-royale-arena]`**, so it
starts with the container and is restarted automatically if it ever exits — no
new infrastructure mechanism.

This mirrors the existing `[program:multizorkd]` precedent for a persistent
game backend, and the `dosdoor_bridge`/Elsewhere env-wiring precedent: the
block is a **live edit to the running container's `supervisord.conf`, not
baked into the Docker image**. It survives an ordinary `docker restart
binkterm-app` (that file is on the container's writable layer, not the
bind-mounted app directory). It does **not** survive the container being
recreated (`docker compose up --force-recreate`, an image rebuild) without
re-applying the block and re-staging `/var/lib/ascii-royale/` — a deliberate,
disclosed limitation matching the `multizorkd`/Elsewhere precedent.

Operational commands (inside the `binkterm-app` container):

```
supervisorctl status ascii-royale-arena     # check state
supervisorctl restart ascii-royale-arena    # restart just the arena (rotates the EndpointId)
supervisorctl tail ascii-royale-arena       # recent wrapper stdout (see the log note below)
```

### Why the arena needs a wrapper, and why it runs as root

The committed launcher (`native-doors/doors/ascii-royale-m3/
launch-ascii-royale.sh`) will only trust an endpoint record that is a
`root:root 0640` regular file inside a `root:root 0750` directory, no older
than 15 s, carrying `version=1`, the pinned SHA, a `host_generation` and a
64-hex `endpoint_id`. Upstream `ascii-royale serve` neither writes that record
nor runs as root. So `[program:ascii-royale-arena]` runs a small privileged
wrapper —
[`ascii-royale-backend/runtime/ascii-royale-arena.sh`](ascii-royale-backend/runtime/ascii-royale-arena.sh)
— that does exactly four privileged things:

1. maintains the `root:root` endpoint channel (atomic publish, ~5 s heartbeat);
2. launches `ascii-royale serve` as the unprivileged **`ascii-royale`**
   account (`setpriv --clear-groups --no-new-privs -- env -i …`); the arena
   itself never runs as root;
3. supervises that one child by exact PID;
4. removes the channel — **and every `.endpoint-id.*` temp file** — on
   `TERM`/`INT`/`EXIT`.

It verifies the deployed binary's SHA-256 against the pin before every launch,
refuses to start on any unsafe path/mode/ownership, never publishes a channel
before a valid ticket exists, picks a fresh `host_generation` each start, and
fails closed (non-zero exit → supervisor restart) on a startup timeout or an
early arena exit. It never prints or logs the EndpointId. See
[`ascii-royale-backend/README.md`](ascii-royale-backend/README.md#the-privileged-wrapper).

### Log privacy note (upstream prints the EndpointId)

Upstream `serve` prints `[arena] ticket: <EndpointId>` and `[arena] join
with: ascii-royale join <EndpointId>` (and `[lobby] announcing on gossip;
bootstrap id <id>`) to **stdout**. That is not patched out. `arena.out.log`
therefore **contains the EndpointId** and must be treated as private runtime
state — same posture as `multizorkd`'s log.

The `[program:ascii-royale-arena]` block points `stdout_logfile` /
`stderr_logfile` at **`/var/lib/ascii-royale/log/`**, *not* `/dev/stdout`, so
the EndpointId never enters the shared `docker logs binkterm-app` stream.
The contract is private at **both** levels:

| | owner:group | mode |
|---|---|---|
| `/var/lib/ascii-royale/log/` | `ascii-royale:ascii-royale` | `0750` |
| `arena.out.log`, `arena.err.log` (+ rotations) | `root:ascii-royale` | `0640` |

supervisord creates and rotates those files with its own umask (`0644`), so
the wrapper (`ascii-royale-arena.sh`) re-applies `0640` `root:ascii-royale` in
preflight **and on every heartbeat** — a rotated `0644` file is re-tightened
within `HEARTBEAT_SECS` (~5 s). `docs`/tests never carry a real EndpointId.

---

## Durable artifact locations

Everything lives under **`/var/lib/ascii-royale/`** inside the container —
outside `/var/www/html` (the bind-mounted git repo), so none of it can be
confused with or committed to tracked source. A dedicated, unprivileged
service account (`ascii-royale`, not `www-data`) owns the arena's own state
and runs `serve`.

| Path | Contents | Ownership / mode |
|---|---|---|
| `/var/lib/ascii-royale/` | service root (must be traversable by the account) | `root:root` `0755` |
| `/var/lib/ascii-royale/bin/ascii-royale-arena.sh` | the privileged wrapper | `root:root` `0755` |
| `/var/lib/ascii-royale/<PIN>/ascii-royale` | the pinned arena binary (path shape required by the launcher: `$runtime_root/$PINNED_SHA/ascii-royale`) | `root:root` `0555` |
| `/var/lib/ascii-royale/alsa-null.conf` | external ALSA null device | `root:root` `0444` |
| `/var/lib/ascii-royale/home/` | arena `HOME` | `ascii-royale:ascii-royale` `0700` |
| `/var/lib/ascii-royale/tmp/` | arena `TMPDIR` | `ascii-royale:ascii-royale` `0700` |
| `/var/lib/ascii-royale/run/` | runtime dir | `root:root` `0755` |
| `/var/lib/ascii-royale/run/private/ticket.raw` | raw 64-hex ticket from `serve --ticket-file` — **private runtime state (the EndpointId)** | `ascii-royale:ascii-royale`, dir `0700` |
| `/var/lib/ascii-royale/run/ascii-royale-m3/endpoint-id` | the record the launcher reads (`ASCII_ROYALE_M3_CHANNEL`) | `root:root` `0640`, dir `root:root` `0750` |
| `/var/lib/ascii-royale/log/` | wrapper + `serve` output dir | `ascii-royale:ascii-royale` `0750` |
| `/var/lib/ascii-royale/log/arena.{out,err}.log` (+ rotations) | wrapper + `serve` output — **contains the EndpointId** (upstream prints it); wrapper enforces the mode each heartbeat | `root:ascii-royale` `0640` |

`<PIN>` = `ac7d9771dfd788b278427db619e43989d4317029`.

The binary is SHA-path-addressed, so an upgrade drops a new
`/var/lib/ascii-royale/<newsha>/ascii-royale` beside the old one and both the
wrapper and the launcher switch by changing the single pinned-SHA constant —
the previous tree stays in place for rollback (see *Upgrade / rollback*).

---

## Launcher wiring (no launcher change)

The committed launcher already supports two environment overrides
(`${VAR:-default}`-then-validate). M4 sets them on the **existing**
`[program:dosdoor_bridge]` `environment=` line — the same mechanism that
already carries `WORLD_GATEWAY_*` / `ELSEWHERE_*` — appended, disturbing
nothing else:

```
ASCII_ROYALE_M3_RUNTIME_ROOT="/var/lib/ascii-royale"
ASCII_ROYALE_M3_CHANNEL="/var/lib/ascii-royale/run/ascii-royale-m3/endpoint-id"
```

With these, the launcher resolves:

| launcher variable | value |
|---|---|
| `runtime_root` | `/var/lib/ascii-royale` |
| `binary` | `/var/lib/ascii-royale/<PIN>/ascii-royale` |
| `alsa_config` | `/var/lib/ascii-royale/alsa-null.conf` |
| `channel` | `/var/lib/ascii-royale/run/ascii-royale-m3/endpoint-id` |

The launcher runs as a child of `dosdoor_bridge`, which runs as **root**, so
it can read the `root:root 0640` channel. (If a future change sets
`user=` on `[program:dosdoor_bridge]`, the launcher can no longer read the
channel and every ascii-royale launch fails closed — call this out before
making such a change.)

<a name="announce"></a>

## `announce` behaviour (accepted, not patched)

`serve` sets `announce: true` internally with `bootstrap: None` and no HTTP
registration. Recon and the locked M4 design established this is acceptable:
the arena never dials the public boxd hub and is not discoverable via
`browse` / `play`; announcement failure is non-fatal (`serve` logs
`[lobby] announce disabled: …` and continues). The only residual cost is a
second bound `iroh` gossip endpoint broadcasting into an unbootstrapped
topic. **This is not patched.** Revisit only if live production evidence shows
a real problem.

`iroh` reachability (`endpoint.online()`, the "waiting to be reachable" phase)
needs outbound access to n0's relay/discovery infrastructure. If the container
loses internet egress, the arena will not produce a ticket; the wrapper then
fails closed after `STARTUP_TIMEOUT` (60 s) and supervisor keeps retrying.

---

## Deploying (Phase B — controlled live provisioning)

Run inside the **current** `binkterm-app` container. This does not rebuild the
image or recreate the container.

```sh
# 1. service account (dedicated, unprivileged, nologin, matching group)
useradd --system --user-group --home-dir /var/lib/ascii-royale \
        --shell /usr/sbin/nologin ascii-royale

# 2. hierarchy
install -d -o root -g root          -m 0755 /var/lib/ascii-royale /var/lib/ascii-royale/bin \
                                            /var/lib/ascii-royale/run
install -d -o ascii-royale -g ascii-royale -m 0700 /var/lib/ascii-royale/home \
                                            /var/lib/ascii-royale/tmp
install -d -o ascii-royale -g ascii-royale -m 0750 /var/lib/ascii-royale/log
install -d -o root -g root          -m 0750 /var/lib/ascii-royale/run/ascii-royale-m3
install -d -o ascii-royale -g ascii-royale -m 0700 /var/lib/ascii-royale/run/private
install -d -o root -g root          -m 0755 /var/lib/ascii-royale/ac7d9771dfd788b278427db619e43989d4317029

# 3. pinned binary (from the retained M3 artifact) + verify
install -m 0555 -o root -g root \
  /var/www/html/data/runtime/ascii-royale-m3/ac7d9771dfd788b278427db619e43989d4317029/ascii-royale \
  /var/lib/ascii-royale/ac7d9771dfd788b278427db619e43989d4317029/ascii-royale
sha256sum /var/lib/ascii-royale/ac7d9771dfd788b278427db619e43989d4317029/ascii-royale
# must equal b7d59c4083e4b2ef3664be57145a70bfbb178db170efbb989e2580fe56d8d84e

# 4. ALSA config + wrapper (from the tracked reference copies)
install -m 0444 -o root -g root \
  /var/www/html/docs/Crossroads/ascii-royale-backend/runtime/alsa-null.conf \
  /var/lib/ascii-royale/alsa-null.conf
install -m 0755 -o root -g root \
  /var/www/html/docs/Crossroads/ascii-royale-backend/runtime/ascii-royale-arena.sh \
  /var/lib/ascii-royale/bin/ascii-royale-arena.sh

# 5. supervisor program — BACK UP THE CONFIG FIRST
cp -a /etc/supervisor/conf.d/supervisord.conf \
      /etc/supervisor/conf.d/supervisord.conf.bak.<UTC-timestamp>
#   append docs/Crossroads/ascii-royale-backend/runtime/supervisord.ascii-royale.conf.fragment
#   append the two ASCII_ROYALE_M3_* assignments to the dosdoor_bridge `environment=` line
supervisorctl reread
supervisorctl add ascii-royale-arena
#   dosdoor_bridge only picks up the new env on restart; do that ONLY with zero
#   open door sessions, and restart ONLY that program:
#   supervisorctl restart dosdoor_bridge
```

Never restart the whole container or unrelated supervisor programs for this.

---

## Validation (record the results in the slice report)

1. `supervisorctl status ascii-royale-arena` → `RUNNING`.
2. the actual `ascii-royale serve` process runs as `ascii-royale` (uid ≠ 0);
   the wrapper is the only root process in the tree.
3. deployed binary SHA-256 == `b7d59c40…d8d84e`
   (`ascii-royale-backend/build/verify-binary.sh`).
4. `/var/lib/ascii-royale/run/ascii-royale-m3/endpoint-id`: regular
   non-symlink, `root:root 0640`, dir `root:root 0750`, ≤ 1024 bytes,
   `version=1` / correct `pinned_sha` / `host_generation` shaped /
   `endpoint_id` 64-hex, `updated_unix` fresh. **Do not print the EndpointId.**
5. the committed launcher, pointed at the production channel through the
   bridge environment, accepts the record with no launcher change (validate
   the record contract; a full human game session is not required).
6. arena logs are under `/var/lib/ascii-royale/log/` (dir `0750`
   `ascii-royale:ascii-royale`; `arena.{out,err}.log` `0640`
   `root:ascii-royale`) and absent from `docker logs binkterm-app`.
7. `supervisorctl restart ascii-royale-arena` → a **new** `host_generation`,
   a fresh valid channel, arena healthy.
8. `ascii-royale-backend/test/run-regression.sh` → all assertions pass
   (startup-timeout + crash + `TERM` cleanup incl. temp files).
9. unrelated services (`multizorkd`, `dosdoor_bridge`, `caddy`, …) healthy.

---

## Ordinary-user + Telnet enablement (M4 Slice 2)

Slice 2 opens ascii-royale as a real Crossroads Experience for **ordinary
authenticated users** on **Web and Telnet**, backed by the same Slice 1
managed arena. It changes no generic BinkTermPHP core, no launcher, no
runtime, no bridge, no identity, and no endpoint channel.

### What changes

**Tracked** — `native-doors/doors/ascii-royale-m3/nativedoor.json`:

| key | before | after | why |
|---|---|---|---|
| `requirements.admin_only` | `true` | **`false`** | `admin_only` is flattened from the manifest by `NativeDoorManifest` and read by every gate (`GameCatalog`, `webdoor-routes`, `door-routes`); it is the **only** manifest-authoritative lever and has no site-local override |
| `game.name` | `ascii-royale (M3 Proof)` | **`ascii-royale`** | user-visible Experience title |
| `game.short_name` | `AR-M3` | **`AR`** | compact label |
| `game.description` | "Administrator-only … proof …" | **"A terminal battle royale — last one standing wins. Fight me next round."** | user-visible blurb / the Experience invitation |

`config.enabled` in the manifest **stays `false`** — manifests are never the
enable switch. `door.max_nodes` stays `4`. `terminal_mode` stays `raw`. The
launch command and every runtime/security field are untouched. `AsciiRoyaleM3ManifestTest`
is updated only where the old proof policy was asserted.

**Site-local** — `config/nativedoors.json`, the `ascii-royale-m3` entry
(git-ignored, admin-managed, authoritative deployment switch):

```
"enabled": true            ← the only change (false → true)
"allow_anonymous": false   "guest_max_sessions": 0   ← no guest access
"credit_cost": 0           ← free
"max_concurrent_sessions": 4   "terminal_size": "80x24"   "max_time_minutes": 30
"hide_from_web": false
```

### Effective policy after enablement

| gate | value | source |
|---|---|---|
| discoverable / launchable by any authenticated non-admin | yes | `admin_only=false` (tracked) + `enabled=true` (site-local) |
| anonymous / guest | blocked | `allow_anonymous=false` **and** `guest_max_sessions=0` (site-local); `/api/door/guest/launch` 403 then 503; Experience routes require login |
| credit cost | free | `credit_cost=0` |
| concurrent BinkTerm callers | 4 | `door.max_nodes` (tracked); a 5th caller gets a clean 503 |
| terminal | 80×24 raw | `terminal_size` (site-local), `terminal_mode` (tracked) |
| session lifetime | 30 min | `max_time_minutes` (site-local) |

### Web + Telnet are coupled

`GameCatalog::addManagedDoors` emits `surfaces = ['web' => 'full', 'telnet' =>
'full']` for every managed door — `telnet` is hardcoded, with no per-surface
toggle. Enabling for Web therefore enables Telnet/SSH at the same instant
(the terminal Crossroads catalog is the same `getEnabledGames()` and the
native-door telnet play path — bridge relay, `native` SS3 cursor-key
normalization — is fully wired). This is intentional for this slice: Telnet
acceptance is performed **before** the site-local switch is left on.

### The technical id stays `ascii-royale-m3`

The directory, manifest path, `ASCII_ROYALE_M3_*` bridge env, and the endpoint
channel path all keep the `-m3` suffix — renaming them is pure churn with no
user-facing benefit. Users never see the raw id in rendered copy
(`ExperiencePresentation` strips it); it appears only in URLs
(`/experiences/ascii-royale-m3`) and page JS/API paths, where `-m3` reads as a
minor version tag, not milestone language. The public **name** is `ascii-royale`.

### Enabling / disabling

Enable (through **Admin → Native Doors**, or by editing the site-local file):
set `ascii-royale-m3.enabled = true`. Disable: set it back to `false` — the
Experience immediately disappears from both catalogs and every launch/route
returns 404/403; the managed arena keeps running (harmless, idle).

### Production-acceptance record — 2026-09-01

`ascii-royale-m3.enabled` was set to `true` and controlled live acceptance was
run with one ordinary authenticated **non-admin** account (`is_admin = false`,
disposable, removed afterward — its door-session and activity rows removed with
it; the pre-existing admin M3 history was retained). The managed Slice 1 arena
(`[program:ascii-royale-arena]`, `serve`, uid 994) was **not restarted** at any
point — same `host_generation` throughout, ~42 min continuous uptime.

**Telnet** (`bbs.l33test.com:23`, real BBS path, UTF-8/ANSI terminal):
discovered in *Games & Experiences → Crossroads* as **ascii-royale –
Multiplayer** (public name and description, no proof/admin copy); detail screen
"Status: Available", "Players online: 0 / 4"; `G Play` launched via the native
door path; the arena lobby roster showed the deterministic callsign
`@ arqa-i (you)` (`arqa` + user id 18 → `arqa-i`); joined the shared managed
arena (9 bots, "the drop fills to 16"); the 80×24 raw TUI rendered coherently
through the lobby → 20-second drop countdown → live match (island map, storm,
status panel); **`w`/`a`/`s`/`d` and the right-arrow key moved the player
marker**; `q` returned cleanly to Crossroads; re-entry produced a fresh session
on the same arena; every session ended `exit_status = normal` with no orphan
launcher / `ascii-royale join` / PTY descendants.

**Web** (authenticated, non-admin): the Experience card and
`/experiences/ascii-royale-m3` lobby rendered with the public name/description,
Multiplayer, Free, capacity `/4`, and **no** "M3 Proof" / "administrator-only"
copy; `POST /api/door/launch` (`surface=web`) returned a session with **no**
`admin_only` / `launch_failed` 403; the web door session spawned
`ascii-royale join <endpoint> --name arqa-i` against the production binary and
endpoint; a second launch while active resumed the **same** session
(`ui.api.door.session_resumed`); `POST /api/door/end` terminated it via the
bridge control socket (`exit_status = normal`) and cleared Experience presence;
0 open sessions and no client descendants afterward.

**Anonymous / guest:** `GET /experiences/ascii-royale-m3` → 401;
`GET /games/nativedoors/ascii-royale-m3` → 302 `/login`;
`POST /api/door/launch` unauthenticated → 401;
`POST /api/door/guest/launch` → 403 *"does not allow anonymous access"*;
`allow_anonymous` stays `false`, `guest_max_sessions` stays `0`.

**EndpointId secrecy:** the current endpoint value appeared in no BBS-visible
output, no rendered web page or JS, no `/api/experiences|door` response, no
`door_sessions` / activity row, and not in `docker logs binkterm-app`. The
arena's own `/var/lib/ascii-royale/log/arena.out.log` (root:ascii-royale 0640,
dir 0750) does contain it, as expected — upstream `serve` prints it.

**Not demonstrated / not claimed:** weapon pickup or firing (not exercised);
any WAN/NAT/relay traversal — the door's `ascii-royale join` process and the
arena's `serve` both run inside `binkterm-app`, so the iroh connection was
local to the container.

**Outcome:** GO. `config/nativedoors.json` `ascii-royale-m3.enabled` left
`true`.

---

## Experience icon (M4 Slice 3)

Presentation-only. Before this slice the Crossroads card and lobby fell back
to the generic site mark (Kludge, orange). ascii-royale now ships a custom
icon through the **existing** native-door icon contract
(`docs/NativeDoors.md` → *Experience icon*) — no template, CSS, catalog, or
route change:

- **Asset:** `native-doors/doors/ascii-royale-m3/icon.png` — a 512×512, 8-bit,
  non-interlaced RGBA PNG (~4 KB). Original artwork made for L33TEST /
  Crossroads (generated with PHP GD; no third-party art, logos, sprites, or
  ANSI). Motif: a terminal targeting reticle locked on a lone combatant
  marker — green on a dark rounded panel, "last one standing".
- **Wiring:** `game.icon: "icon.png"` in `nativedoor.json` (the only tracked
  manifest change). `NativeDoorManifest` flattens it to `door['icon']`;
  `GameCatalog` exposes `presentation.icon_url = /door-assets/ascii-royale-m3/icon`;
  `routes/door-routes.php` serves the file as `image/png`.
- Renders at 48×48 on `/games` and `/crossroads` cards and "Your Places", and
  120×120 on the `/experiences/ascii-royale-m3` lobby (`object-fit: cover`).
  The terminal Crossroads (Telnet/SSH) shows no icon and is unaffected.
- Regression: `tests/Unit/CrossroadsExperienceIconTest.php` gains an
  ascii-royale block (canonical-asset, manifest, catalog-presentation)
  alongside the existing LORD / Tristam / BCR cases.

---

## Upgrade / rollback

- **Upgrade the binary:** build + verify a new binary
  (`ascii-royale-backend/build/`), `install -m 0555` it at
  `/var/lib/ascii-royale/<newsha>/ascii-royale`, update the pinned-SHA
  constant in the deployed wrapper and the `PINNED_SHA` in the committed
  launcher (a tracked change — its own review), then
  `supervisorctl restart ascii-royale-arena`. Keep the old
  `/var/lib/ascii-royale/<oldsha>/` tree in place until the new binary soaks.
- **Rollback:** revert the two SHA constants and restart the program; the old
  SHA-addressed tree is still there.
- **Config:** the `supervisord.conf` block and the two bridge env assignments
  are the entire deployment surface; the `.bak.<timestamp>` copy is the
  restore point.

---

## Deferred / disclosed limitations (as of this slice)

- `/var/lib/ascii-royale` and the `supervisord.conf` edit live on the
  `binkterm-app` container writable layer. **Recreating that container loses
  the arena deployment** (not the durable player state — the arena keeps
  none — but the binary, wrapper and config edit). Same limitation as
  `multizorkd`.
- `serve`'s `announce: true` is accepted, not patched (see
  [above](#announce)).
- The EndpointId rotates on every arena restart (`serve` binds a fresh
  endpoint; no secret-key persistence). New launches always read the fresh
  record; a caller mid-`join` during a restart must relaunch. Persistent
  identity would need upstream support.
- `/var/lib/ascii-royale/log/arena.out.log` contains the EndpointId (upstream
  prints it). Private at both levels (dir `0750`, files `0640`
  `root:ascii-royale`, wrapper-enforced each heartbeat), not redacted.
- Ordinary-user enablement and Telnet are **out of scope for this slice**.
