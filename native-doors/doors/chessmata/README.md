# Chessmata NativeDoor (Crossroads Experience #4 — Telnet surface)

Slice 3. The **Telnet/classic-BBS** surface for Chessmata: an authenticated
BinkTerm caller launches the **official upstream Chessmata CLI** against the
L33TEST **self-hosted** Chessmata service, already signed in as their own
Chessmata account (the one `ChessmataIdentity` maps their BinkTerm identity to).
No Chessmata login / registration / email / password / API-key prompt.

WebDoor / graphical-Web integration and the unified single-Experience catalog
entry are later slices — this door is Telnet-only (`hide_from_web: true`).

## Files (all tracked)

| File | Role |
|---|---|
| `nativedoor.json` | manifest — `terminal_mode: raw`, `admin_only: true`, `launch_command` passes `{user_number}` (= `door_sessions.user_id`, the authenticated caller) + `{user_name}` |
| `launch-chessmata.sh` | thin L33TEST wrapper — NOT a Chessmata client. Validates the caller id, makes an ephemeral private `XDG_CONFIG_HOME` (`mktemp -d`, `0700`), calls `session-init.php`, runs `python3 -m chessmata <subcommand>` from a tiny dispatch menu, wipes the session dir on every exit path (`trap … EXIT INT TERM HUP`). The upstream CLI is backgrounded + `wait`ed (so the cleanup trap can reap it on a mid-game disconnect) with the caller PTY preserved on fd 3 — bash otherwise redirects an async command's stdin from `/dev/null` and the client's `input()` calls would hit EOF. |
| `session-init.php` | CLI shim → `ChessmataTerminalSession::prepare()`; prints only safe JSON metadata, exit codes 0/2/3/1 |

`launch-chessmata.sh` honours three `CHESSMATA_*` env overrides (`CHESSMATA_CLI_ROOT`,
`CHESSMATA_SESSION_INIT`, `CHESSMATA_PHP_BIN`) **only** so its own test harness
(`tests/Unit/ChessmataTerminalSessionTest.php`) can substitute a fake CLI and a
fake session-init. They are never set in the NativeDoor runtime env.

The broker + credential logic lives in `src/Crossroads/ChessmataTerminalSession.php`
and `src/Crossroads/ChessmataIdentity.php` (Slice 2).

## The official CLI

Image-baked at **`/opt/chessmata-cli`** (read-only) by `docker/Dockerfile`'s
`chessmata-cli` build stage: the pinned upstream
`e55b514565b2b4689360a58fb350afda5bb4faf5` + the same carried patches as the
self-hosted service (`docker/chessmata/patches/*.patch`, sorted, fail-closed
`git apply --check`). Provenance markers `/opt/chessmata-cli/CHESSMATA_PIN` and
`/opt/chessmata-cli/CHESSMATA_PATCHES.sha256`. Never a pip / global install.

## Credential handling

`session-init.php` → `ChessmataTerminalSession::prepare($userId, $xdgConfigHome)`:

1. requires `$userId > 0` (an authenticated BinkTerm account);
2. `ChessmataIdentity::resolve()` — provisions the caller's Chessmata account
   once, reuses it forever;
3. `ChessmataIdentity::terminalCredential()` — the account's durable `cmk_` key;
4. writes `<xdg>/chessmata/config.json` (`server_url` = `CHESSMATA_INTERNAL_URL`,
   default `http://chessmata:9029` — the self-hosted service, **never**
   `chessmata.metavert.io`) and `<xdg>/chessmata/credentials.json`
   (`access_token` = the `cmk_` key) — both `0600` inside a `0700` dir;
5. returns `{ok, display_name, chessmata_user_id, server_url}` — **no secret**.

The API key is only ever in PHP memory and `credentials.json`. It is never in
argv, stdout, a log, a dropfile, an env var, or a shell variable. `set -x` is
deliberately not used in the launcher.

## Isolation & lifecycle

All door PTYs run as the same OS user (root, via `dosdoor_bridge`), as every
BinkTerm door does. Per-caller isolation is by an **unguessable `mktemp -d`
path + `0700` + wipe on every exit path** (normal quit, `Q`, `EOF`, `SIGINT`,
`SIGTERM`, `SIGHUP` from the bridge killing the PTY). The `trap` also kills any
still-running child CLI. Nothing persists between sessions — game state is
server-side; a relaunch re-resolves to the **same** Chessmata account.

## Enablement (deploy config, not committed)

`config/nativedoors.json` (git-ignored, admin-managed):
```json
"chessmata": {
  "enabled": true, "credit_cost": 0, "terminal_size": "80x24",
  "max_time_minutes": 120, "max_concurrent_sessions": 6,
  "allow_anonymous": false, "guest_max_sessions": 0, "hide_from_web": true
}
```
Plus `requirements.admin_only: true` in the manifest until acceptance/rollout.
