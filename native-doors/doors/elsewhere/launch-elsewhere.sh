#!/bin/bash
#
# Elsewhere -- local home-adapter / launch-composition seam.
#
# Elsewhere is L33TEST's persistent multiplayer world, powered by the Tangaria
# engine. This wrapper is invoked by the BinktermPHP native-door bridge
# (scripts/dosbox-bridge, "launch_command": "/bin/bash launch-elsewhere.sh")
# inside a real PTY, with the authenticated caller's identity already placed in
# the environment by trusted BinkTerm launch machinery.
#
# All deployment configuration reaches this wrapper through that trusted process
# environment (Docker / supervisord). The wrapper sources NO configuration file
# of its own -- there is deliberately no env-file / `source` mechanism here.
#
# It does NOT talk to any game protocol itself. It composes the four reviewed
# World Gateway primitives --
#
#     world-gateway resolve        (generic identity mapping)
#     world-gateway provision      (Tangaria account for this subject)
#     world-gateway prepare-launch (private per-session client environment)
#     world-gateway cleanup-launch (teardown of that private session)
#
# -- and then runs the pinned Tangaria GCU client (pwmangclient) as a CHILD
# process pointed at the private per-session HOME the gateway prepared.
#
# Identity model (M4E):
#   * The ONLY durable identity input is DOOR_USER_NUMBER = BinkTerm users.id
#     (immutable SERIAL PK). username / real name / display name are NEVER used
#     as durable identity -- advisory only.
#   * home_bbs_id is "local": an M4 implementation trust-domain identifier for
#     "the one trusted local home board", NOT the eventual federation board id.
#     The durable board identifier "l33test" is reserved for the configurable /
#     signed-issuer milestone (M9).
#   * world_id is "elsewhere". Tangaria is the provider/engine, not the world id.
#
# Trust boundary (M4E, LOCAL only):
#   DOOR_USER_NUMBER is trusted because the only writers of that value are
#   first-party in-container components running above the player (authenticated
#   php-fpm -> door_sessions row -> root bridge -> this PTY child) and the
#   player has no input channel into it. It is NOT a federation assertion and
#   carries no freshness / anti-replay guarantee. M9 replaces this with a
#   signed home-BBS assertion verified by the gateway.
#
# Secret safety:
#   The Tangaria account name/secret/hash NEVER pass through this wrapper's
#   argv, environment, stdout/stderr, process title, logs, or the BinkTerm drop
#   file. prepare-launch returns only {session_dir, cleanup_token,
#   server_endpoint}; the plaintext credential lives solely in
#   <session_dir>/.pwmangrc (mode 0600), created and torn down by the gateway.
#
# Cleanup ownership:
#   This wrapper owns teardown of the ephemeral private session for NORMAL EXIT
#   and TRAPPABLE signals/disconnects (EXIT/INT/TERM/HUP -- the bridge sends
#   SIGHUP via node-pty on browser close, SIGTERM on /api/door/end). It does
#   NOT exec the client, so the trap survives. SIGKILL / silently-dropped
#   sockets leaving an orphaned private .pwmangrc remain a tracked M7
#   operational-hardening condition (out of scope here).
#
set -u
set +x   # never enable shell tracing: keep gateway output and paths off the tty

# ---------------------------------------------------------------------------
# operator-facing friendly failure + optional (secret-free) operator diagnostics
# ---------------------------------------------------------------------------
DIAG_LOG="${ELSEWHERE_DIAG_LOG:-}"
if [ -n "$DIAG_LOG" ]; then
    # opt-in operator log: make sure we can actually append to it, otherwise
    # ignore it rather than letting a bad path break every launch.
    if ! { : >>"$DIAG_LOG"; } 2>/dev/null; then
        DIAG_LOG=""
    fi
fi

diag() {
    # operator-only channel; never the player's terminal. Contains no secrets
    # (this wrapper never holds one) but may contain deployment paths, so it is
    # written only when the operator has opted in with ELSEWHERE_DIAG_LOG.
    [ -n "$DIAG_LOG" ] || return 0
    printf '%s elsewhere-wrapper: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" \
        >>"$DIAG_LOG" 2>/dev/null || true
}

fail() {
    # $1 = friendly player-facing reason (no paths, no internals)
    # $2 = operator diagnostic detail (optional)
    printf '\r\n\033[1;33mElsewhere is temporarily unavailable.\033[0m\r\n' 2>/dev/null || true
    if [ -n "${1:-}" ]; then
        printf '%s\r\n' "$1" 2>/dev/null || true
    fi
    printf '\r\nPress any key to return to the BBS.\r\n' 2>/dev/null || true
    [ -n "${2:-}" ] && diag "FAIL: $2"
    # give the player a moment to read before the PTY closes
    read -r -n 1 -s -t 30 _ 2>/dev/null || true
    exit 1
}

# ---------------------------------------------------------------------------
# 1. authenticated identity -- fail closed on anything that is not a real
#    positive integer BinkTerm users.id
# ---------------------------------------------------------------------------
HOME_USER="${DOOR_USER_NUMBER:-}"

case "$HOME_USER" in
    '')          fail "You must be signed in to enter Elsewhere." "DOOR_USER_NUMBER missing/empty" ;;
    *[!0-9]*)    fail "You must be signed in to enter Elsewhere." "DOOR_USER_NUMBER not numeric: '$HOME_USER'" ;;
esac
if [ "$((10#$HOME_USER))" -le 0 ]; then
    fail "You must be signed in to enter Elsewhere." "DOOR_USER_NUMBER <= 0: '$HOME_USER'"
fi

# Canonicalize to plain base-10 (e.g. "007" -> "7") so the durable World Gateway
# subject key never varies by leading-zero formatting. This does not change the
# identity source: DOOR_USER_NUMBER remains the only durable identity input.
HOME_USER="$((10#$HOME_USER))"

# Anonymous Elsewhere is primarily denied by BinkTerm configuration
# (nativedoors.json allow_anonymous=false + no /api/door/guest allowance).
# If the deployment tells us the shared guest system-user id, refuse it here
# too (defence in depth, no BinkTerm DB query).
GUEST_ID="${ELSEWHERE_GUEST_USER_ID:-}"
case "$GUEST_ID" in
    ''|*[!0-9]*) : ;;  # unset or misconfigured -> rely on BinkTerm's denial
    *)
        if [ "$((10#$HOME_USER))" -eq "$((10#$GUEST_ID))" ]; then
            fail "Elsewhere requires a personal L33TEST account." \
                 "refused shared guest user id $HOME_USER"
        fi
        ;;
esac

# ---------------------------------------------------------------------------
# 2. deployment configuration
#    Safe defaults ONLY where the deployment contract is already stable
#    (loopback-only world, pinned engine default port). Everything else fails
#    closed rather than guessing a host path.
# ---------------------------------------------------------------------------
WGW_BIN="${WORLD_GATEWAY_BIN:-}"
WGW_DB="${WORLD_GATEWAY_DB:-}"
WGW_RT="${WORLD_GATEWAY_RUNTIME_ROOT:-}"
ACCOUNT_FILE="${ELSEWHERE_ACCOUNT_FILE:-}"
CLIENT_DIR="${ELSEWHERE_CLIENT_DIR:-}"
CLIENT_BIN="${ELSEWHERE_CLIENT_BIN:-pwmangclient}"
BIRTH_PREF_DIR="${ELSEWHERE_BIRTH_PREF_DIR:-}"
SERVER_HOST="${ELSEWHERE_SERVER_HOST:-127.0.0.1}"   # world is loopback-only (C4)
SERVER_PORT="${ELSEWHERE_SERVER_PORT:-18346}"       # pinned pwmangband default
ESCDELAY_VALUE="${ELSEWHERE_ESCDELAY:-20}"          # M2/M3 finding

[ -n "$WGW_BIN" ]        || fail "The world is not configured correctly." "WORLD_GATEWAY_BIN unset"
[ -x "$WGW_BIN" ]        || fail "The world is not configured correctly." "WORLD_GATEWAY_BIN not executable: $WGW_BIN"
[ -n "$WGW_DB" ]         || fail "The world is not configured correctly." "WORLD_GATEWAY_DB unset"
[ -n "$WGW_RT" ]         || fail "The world is not configured correctly." "WORLD_GATEWAY_RUNTIME_ROOT unset"
[ -n "$ACCOUNT_FILE" ]   || fail "The world is not configured correctly." "ELSEWHERE_ACCOUNT_FILE unset"
[ -n "$CLIENT_DIR" ]     || fail "The world is not configured correctly." "ELSEWHERE_CLIENT_DIR unset"
[ -d "$CLIENT_DIR" ]     || fail "The world is not configured correctly." "ELSEWHERE_CLIENT_DIR not a directory: $CLIENT_DIR"
[ -n "$BIRTH_PREF_DIR" ] || fail "The world is not configured correctly." "ELSEWHERE_BIRTH_PREF_DIR unset (required: Tangaria resolves its pref path via getpwuid(), not HOME)"

case "$SERVER_PORT" in
    ''|*[!0-9]*) fail "The world is not configured correctly." "ELSEWHERE_SERVER_PORT not numeric: '$SERVER_PORT'" ;;
esac

# ESCDELAY is deployment-controlled but must be a non-negative decimal integer
# (it is handed to the client as an env value); reject garbage before launch.
case "$ESCDELAY_VALUE" in
    ''|*[!0-9]*) fail "The world is not configured correctly." "ELSEWHERE_ESCDELAY is not a non-negative integer: '$ESCDELAY_VALUE'" ;;
esac

# Make every operator path absolute now, before any `cd`, so the cleanup trap
# (which runs after we cd into the client dir) always resolves correctly.
_abs() { case "$1" in /*) printf '%s' "$1" ;; *) printf '%s/%s' "$(pwd)" "$1" ;; esac; }
WGW_BIN="$(_abs "$WGW_BIN")"
WGW_DB="$(_abs "$WGW_DB")"
WGW_RT="$(_abs "$WGW_RT")"
ACCOUNT_FILE="$(_abs "$ACCOUNT_FILE")"
BIRTH_PREF_DIR="$(_abs "$BIRTH_PREF_DIR")"
CLIENT_DIR="$(_abs "$CLIENT_DIR")"
# the opt-in operator diag log too, so post-cd writes land in the same file
[ -n "$DIAG_LOG" ] && DIAG_LOG="$(_abs "$DIAG_LOG")"

# resolve the client binary to an absolute path (bare name -> inside CLIENT_DIR)
case "$CLIENT_BIN" in
    /*) : ;;
    *)  CLIENT_BIN="$CLIENT_DIR/$CLIENT_BIN" ;;
esac
[ -x "$CLIENT_BIN" ] || fail "The world is not configured correctly." "client binary not executable: $CLIENT_BIN"

WORLD_ID="elsewhere"
HOME_BBS="local"   # M4 trust-domain id, NOT the federation board id (reserved: l33test)

# ---------------------------------------------------------------------------
# 3. World Gateway composition: resolve -> provision -> prepare-launch
#    (explicit ordering; each step is idempotent and safe to re-run)
# ---------------------------------------------------------------------------

# 3a. resolve -- generic identity mapping (world_subject_id)
if ! "$WGW_BIN" --db "$WGW_DB" resolve \
        --world "$WORLD_ID" --home-bbs "$HOME_BBS" --home-user "$HOME_USER" \
        >/dev/null 2>>"${DIAG_LOG:-/dev/null}"; then
    fail "" "resolve failed for user $HOME_USER"
fi

# 3b. provision -- Tangaria account for this subject (idempotent)
if ! "$WGW_BIN" --db "$WGW_DB" provision \
        --world "$WORLD_ID" --home-bbs "$HOME_BBS" --home-user "$HOME_USER" \
        --account-file "$ACCOUNT_FILE" \
        >/dev/null 2>>"${DIAG_LOG:-/dev/null}"; then
    fail "" "provision failed for user $HOME_USER"
fi

# 3c. prepare-launch -- private per-session client environment.
#     Capture stdout (safe JSON metadata only); never echo it.
PREP_JSON="$(
    "$WGW_BIN" --db "$WGW_DB" prepare-launch \
        --world "$WORLD_ID" --home-bbs "$HOME_BBS" --home-user "$HOME_USER" \
        --runtime-root "$WGW_RT" \
        --server-host "$SERVER_HOST" --server-port "$SERVER_PORT" \
        --birth-pref-dir "$BIRTH_PREF_DIR" \
        2>>"${DIAG_LOG:-/dev/null}"
)" || fail "" "prepare-launch failed for user $HOME_USER"

# ---------------------------------------------------------------------------
# 4. parse ONLY session_dir + cleanup_token with a real JSON parser
# ---------------------------------------------------------------------------
parse_field() {
    # $1 = key. Reads the JSON on stdin. Prints the string value or nothing.
    python3 -c '
import sys, json
try:
    d = json.load(sys.stdin)
except Exception:
    sys.exit(2)
v = d.get(sys.argv[1])
if isinstance(v, str) and v:
    sys.stdout.write(v)
else:
    sys.exit(3)
' "$1"
}

SESSION_DIR="$(printf '%s' "$PREP_JSON" | parse_field session_dir)" \
    || fail "" "prepare-launch output missing session_dir"
CLEANUP_TOKEN="$(printf '%s' "$PREP_JSON" | parse_field cleanup_token)" \
    || fail "" "prepare-launch output missing cleanup_token"
PREP_JSON=""   # drop it; nothing downstream needs the blob

# validate before we launch anything
[ -d "$SESSION_DIR" ] || fail "" "session_dir is not a directory: $SESSION_DIR"
[ -f "$SESSION_DIR/.pwmangrc" ] || fail "" "session config missing under session_dir"
# exact M4D session-token contract: s_ + 24 [0-9A-Za-z]. The gateway
# revalidates this itself; enforcing it here too keeps a malformed token from
# ever reaching cleanup-launch. Literal ERE -- no expansion in command position.
if [[ ! "$CLEANUP_TOKEN" =~ ^s_[0-9A-Za-z]{24}$ ]]; then
    fail "" "implausible cleanup token"
fi

# ---------------------------------------------------------------------------
# 5. cleanup trap -- runs exactly once, on normal exit or trappable signal
# ---------------------------------------------------------------------------
CLIENT_PID=""
_cleanup_ran=0

cleanup() {
    [ "$_cleanup_ran" = 1 ] && return 0
    _cleanup_ran=1
    trap - EXIT INT TERM HUP

    if [ -n "$CLIENT_PID" ] && kill -0 "$CLIENT_PID" 2>/dev/null; then
        kill -TERM "$CLIENT_PID" 2>/dev/null || true
        i=0
        while [ "$i" -lt 20 ] && kill -0 "$CLIENT_PID" 2>/dev/null; do
            sleep 0.25
            i=$((i + 1))
        done
        kill -KILL "$CLIENT_PID" 2>/dev/null || true
    fi

    # cleanup-launch is idempotent: "removed:false" (already gone) is fine.
    "$WGW_BIN" --db "$WGW_DB" cleanup-launch \
        --world "$WORLD_ID" --runtime-root "$WGW_RT" --session "$CLEANUP_TOKEN" \
        >/dev/null 2>>"${DIAG_LOG:-/dev/null}" \
        || diag "cleanup-launch returned non-zero for token $CLEANUP_TOKEN"
}
trap cleanup EXIT INT TERM HUP

# ---------------------------------------------------------------------------
# 6. run the Tangaria client as a CHILD (never exec) so the trap survives.
#    HOME is scoped to the client only and points at the private session dir;
#    TERM is preserved from BinkTerm (xterm-256color); ESCDELAY per M2/M3.
#
#    The client is backgrounded (`&`) so this wrapper stays alive to wait on
#    it, propagate signals, and run cleanup-launch. In a non-interactive shell
#    (no job control) an asynchronous command's stdin is assigned to /dev/null
#    "before any explicit redirections" (POSIX). The GCU client reads keyboard
#    input from fd 0, so it MUST be given this wrapper's stdin -- the real PTY
#    slave from the native-door bridge. `<&0` is that explicit redirection: it
#    overrides the automatic /dev/null. Output (fd 1/2) is inherited normally.
# ---------------------------------------------------------------------------
cd "$CLIENT_DIR" || fail "" "cannot cd to client dir: $CLIENT_DIR"

diag "launching client for user $HOME_USER (token $CLEANUP_TOKEN)"

HOME="$SESSION_DIR" \
TERM="${TERM:-xterm-256color}" \
ESCDELAY="$ESCDELAY_VALUE" \
    "$CLIENT_BIN" <&0 &
CLIENT_PID=$!

wait "$CLIENT_PID"
CLIENT_RC=$?

cleanup
exit "$CLIENT_RC"
