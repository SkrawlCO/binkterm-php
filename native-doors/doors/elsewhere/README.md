# Elsewhere

**Elsewhere is L33TEST's persistent multiplayer world, powered by Tangaria.**

- **World:** Elsewhere — *A Tangaria World* — *Hosted by L33TEST*
- **Provider / engine:** Tangaria (PWMAngband). Tangaria is the software
  underneath Elsewhere; it is **not** the world identity.
- **Machine world id:** `elsewhere`

This native door is the **local home-adapter seam** between an authenticated
BinkTerm launch and the standalone **World Gateway**
(`/root/world-gateway`, `bin/world-gateway`). It adds no game-protocol code to
BinkTerm and patches nothing in Tangaria.

## What happens on launch

`nativedoor.json` points the bridge at `launch-elsewhere.sh`, which runs inside
the same real PTY every native door gets. The wrapper:

1. Reads the authenticated caller's identity from the trusted native-door
   environment: **`DOOR_USER_NUMBER` = BinkTerm `users.id`** (an immutable
   `SERIAL` primary key). It fails closed if that value is missing, empty,
   non-numeric, or `<= 0`, then canonicalizes it to plain base-10 (`007` → `7`)
   so the durable gateway subject key never varies by formatting. Username /
   real name / display name are **never** used as durable identity — advisory
   only.
2. Composes the four reviewed World Gateway primitives, in order:
   `world-gateway resolve` → `provision` → `prepare-launch`
   (`--world elsewhere --home-bbs local --home-user <users.id>`), each
   idempotent and safe to re-run every launch.
3. Parses **only** `session_dir` and `cleanup_token` from `prepare-launch`
   (with `python3`, not text hacks) and never prints that JSON to the player.
4. Installs a cleanup trap, then runs the pinned Tangaria GCU client
   (`pwmangclient`) **as a child** (never `exec`, so the trap survives) with
   `HOME` scoped to the private per-session directory, `TERM` preserved from
   BinkTerm, and `ESCDELAY=20`.
5. On the client exiting, or on `INT` / `TERM` / `HUP` (the bridge sends
   `SIGHUP` via node-pty when the browser tab closes, `SIGTERM` on
   `/api/door/end`), runs `world-gateway cleanup-launch <cleanup_token>`
   **exactly once** and terminates the client if it is still alive.

## Identity & trust

- **`home_bbs_id` is `local`.** This is an **M4 implementation trust-domain
  identifier** meaning "the one trusted local home board" — it is **NOT** the
  eventual federation board identifier. The durable board id **`l33test`** is
  reserved for the configurable / signed-issuer milestone (M9), when
  `home_bbs_id` becomes load-bearing across boards.
- **Local trust only.** `DOOR_USER_NUMBER` is trusted because its only writers
  are first-party in-container components running above the player
  (authenticated PHP → `door_sessions` row → root bridge → this PTY child) and
  the player has no channel into it. It is not a signed assertion and carries
  no freshness / anti-replay guarantee. M9 replaces this with a signed
  home-BBS assertion verified by the gateway; nothing downstream of the
  gateway's normalized subject changes.
- A future Synchronet / Mystic home adapter reuses this same wrapper shape,
  differing only in how it obtains its user id and in the `--home-bbs` value.

## Credentials

The player **never** knows, types, or manages a Tangaria account or password.
Tangaria's client always renders a **Name** prompt, so the **opaque** account
name may be briefly visible on screen; the password is shown only as a masked
default and the player just presses Enter. The plaintext credential lives
**only** in `<session_dir>/.pwmangrc` (mode `0600`), created and removed by the
World Gateway. It never appears in this wrapper's argv, environment,
stdout/stderr, process title, logs, or the BinkTerm drop file — the wrapper
never even receives it.

## Anonymous / guest

Disabled. Primary denial is BinkTerm configuration
(`config/nativedoors.json`: `allow_anonymous: false`, `guest_max_sessions: 0`,
and `/api/door/launch` requires authentication). As defence in depth the
wrapper also refuses a `DOOR_USER_NUMBER` equal to `ELSEWHERE_GUEST_USER_ID`
when the deployment sets that (no BinkTerm DB query is performed).

## M4E validation status

`requirements.admin_only` is **`true`** for M4E validation — only the operator
can launch Elsewhere until the world is opened.

## Deferred to M7 (not handled here)

- **Orphan / SIGKILL cleanup.** The wrapper owns teardown for normal exit and
  trappable signals only. A `SIGKILL` (bridge force-kill backstop) or a
  silently dropped socket can leave an orphaned private `.pwmangrc` (`0600`, in
  a `0700` runtime root) until a runtime-root sweep reaps it. That sweep is a
  tracked M7 operational-hardening item.
- **Dedicated unprivileged runtime user.** The bridge (and this wrapper) run as
  root in the container today. A dedicated `elsewhere` OS user for the client +
  its `getpwuid()` birth-pref home is deferred to M7. Because M4D's Tangaria
  pref path resolves via `getpwuid()` and not `HOME`, this wrapper **always**
  passes an explicit `--birth-pref-dir` (`ELSEWHERE_BIRTH_PREF_DIR`).

## Configuration (environment)

Set by the deployment through the wrapper's process environment (e.g. a
supervisord `environment=` line or a Docker `env_file`). The wrapper sources
no configuration file of its own. Nothing deployment-specific is hard-coded in
the wrapper.

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `WORLD_GATEWAY_BIN` | yes | — | path to `world-gateway` CLI |
| `WORLD_GATEWAY_DB` | yes | — | gateway SQLite DB path |
| `WORLD_GATEWAY_RUNTIME_ROOT` | yes | — | private root for ephemeral session dirs |
| `ELSEWHERE_ACCOUNT_FILE` | yes | — | Tangaria engine `account` file |
| `ELSEWHERE_CLIENT_DIR` | yes | — | `pwmangclient` install dir (cwd for `lib/` lookups) |
| `ELSEWHERE_BIRTH_PREF_DIR` | yes | — | fixed dir passed as `--birth-pref-dir` |
| `ELSEWHERE_CLIENT_BIN` | no | `pwmangclient` | client binary (bare name resolved inside `ELSEWHERE_CLIENT_DIR`) |
| `ELSEWHERE_SERVER_HOST` | no | `127.0.0.1` | world server host (loopback-only until M7) |
| `ELSEWHERE_SERVER_PORT` | no | `18346` | world server port (pinned pwmangband default) |
| `ELSEWHERE_ESCDELAY` | no | `20` | client `ESCDELAY` (non-negative integer; malformed values fail closed) |
| `ELSEWHERE_GUEST_USER_ID` | no | — | shared guest `users.id` to refuse (defence in depth) |
| `ELSEWHERE_DIAG_LOG` | no | — | opt-in operator diagnostics file (secret-free); absolutized before use; ignored if not writable |
