#!/bin/bash
#
# Chessmata NativeDoor launcher (Crossroads Experience #4, Telnet surface).
#
# Thin L33TEST wrapper around the OFFICIAL upstream Chessmata CLI (image-baked
# at /opt/chessmata-cli, same pin + carried patches as the self-hosted service).
# It does NOT reimplement any Chessmata UI -- the chess board, menus and input
# are 100% the upstream `python3 -m chessmata` client.
#
#   invoked by the dosbox-bridge NativeAdapter as:
#     /bin/bash launch-chessmata.sh "{user_number}" "{user_name}"
#
# {user_number} is door_sessions.user_id -- the authenticated BinkTerm caller,
# set server-side by the auth-checked /api/door/launch endpoint, not caller data.
#
set -u
set -o pipefail
# Deliberately NO `set -x` -- tracing would print paths (and, in a bad future
# edit, could print a secret). Keep it off.

readonly SELF_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# The three paths below are fixed in production. The CHESSMATA_* overrides exist
# ONLY for the launcher's own test harness (tests/Unit/ChessmataTerminalSessionTest)
# so it can substitute a fake session-init and a fake CLI; they are never set in
# the NativeDoor runtime env.
readonly CLI_ROOT="${CHESSMATA_CLI_ROOT:-/opt/chessmata-cli}"
readonly INIT_PHP="${CHESSMATA_SESSION_INIT:-${SELF_DIR}/session-init.php}"
readonly PHP_BIN="${CHESSMATA_PHP_BIN:-$(command -v php || echo /usr/local/bin/php)}"

cr() { printf '%s\r\n' "$*"; }

fail_generic() {
    cr ''
    cr 'Chessmata is temporarily unavailable. Please try again later.'
    exit 1
}

# --- identity (from the NativeDoor boundary) ----------------------------------
user_id="${1:-${DOOR_USER_NUMBER:-0}}"
if [[ ! "$user_id" =~ ^[1-9][0-9]*$ ]] || (( user_id > 2147483647 )); then
    cr ''
    cr 'Chessmata needs a logged-in BinkTerm account. Please sign in and try again.'
    exit 1
fi

# --- ephemeral, private per-session config dir ------------------------------
SESSION_DIR="$(mktemp -d "${TMPDIR:-/tmp}/chessmata-sess.XXXXXXXXXX")" || fail_generic
chmod 700 -- "$SESSION_DIR"

cleanup() {
    # kill any child CLI still running, then wipe the credential material
    if [[ -n "${CLI_PID:-}" ]]; then
        kill "$CLI_PID" 2>/dev/null || true
        wait "$CLI_PID" 2>/dev/null || true
    fi
    rm -rf -- "$SESSION_DIR" 2>/dev/null || true
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP

# Preserve the real caller PTY on fd 3. run_cli backgrounds the CLI (so the
# cleanup trap can still reap it), and bash redirects an async command's stdin
# from /dev/null unless we give it an explicit redirection -- without this the
# upstream client's input() calls hit EOF immediately.
exec 3<&0

export HOME="$SESSION_DIR"
export XDG_CONFIG_HOME="$SESSION_DIR/config"
mkdir -p -m 700 -- "$XDG_CONFIG_HOME" || fail_generic

# --- resolve identity + write the OFFICIAL CLI credential/config files -------
# session-init.php prints ONLY safe JSON metadata; the API key goes straight
# into $XDG_CONFIG_HOME/chessmata/credentials.json (0600), never to a variable.
err_file="$SESSION_DIR/.init.err"
meta="$("$PHP_BIN" "$INIT_PHP" "$user_id" "$XDG_CONFIG_HOME" 2>"$err_file")"
rc=$?
if (( rc != 0 )); then
    reason="$(cat -- "$err_file" 2>/dev/null)"
    cr ''
    case "$reason" in
        RATE_LIMITED)      cr 'Chessmata is onboarding a lot of new players right now. Please try again in a few minutes.' ;;
        NOT_AUTHENTICATED) cr 'Chessmata needs a logged-in BinkTerm account.' ;;
        *)                 cr 'Chessmata is temporarily unavailable. Please try again later.' ;;
    esac
    exit 1
fi
rm -f -- "$err_file"

# meta = {"ok":true,"display_name":"...","chessmata_user_id":"...","server_url":"http://chessmata:9029"}
display_name="$(printf '%s' "$meta" | sed -n 's/.*"display_name":"\([^"]*\)".*/\1/p')"
server_host="$(printf '%s' "$meta" | sed -n 's#.*"server_url":"[a-z]*://\([^/"]*\)".*#\1#p')"
[[ -n "$display_name" ]] || display_name='you'
[[ -n "$server_host" ]] || server_host='the L33TEST chess service'

# --- run the OFFICIAL CLI -----------------------------------------------------
run_cli() {
    # The caller drives the upstream UI directly. Backgrounded + waited so the
    # cleanup trap can reap it on a mid-game disconnect; stdin is the saved
    # caller PTY (fd 3), stdout/stderr are inherited.
    ( cd -- "$CLI_ROOT" && exec python3 -m chessmata "$@" ) <&3 &
    CLI_PID=$!
    wait "$CLI_PID"
    local rc=$?
    CLI_PID=''
    return $rc
}

printf '\033[2J\033[H'
cr "CHESSMATA  --  connected to ${server_host}"
cr "You are playing as: ${display_name}   (no login needed -- your BinkTerm account is your Chessmata account)"
cr ''

while :; do
    cr ''
    cr '  [P] Play / create a game        [M] Find a match (matchmaking)'
    cr '  [J] Join a game by code         [L] Lobby (who is waiting)'
    cr '  [G] Active games                [H] My game history'
    cr '  [T] Top players                 [Q] Back to BinkTerm'
    printf '  > '
    if ! IFS= read -r choice; then
        break
    fi
    case "$(printf '%s' "${choice:-}" | tr 'A-Z' 'a-z' | tr -d '[:space:]')" in
        p|play)            run_cli play ;;
        m|match)           run_cli match ;;
        j|join)
            printf '  Game code or link: '
            IFS= read -r code || true
            [[ -n "${code:-}" ]] && run_cli play "$code"
            ;;
        l|lobby)           run_cli lobby ;;
        g|games)           run_cli games ;;
        h|history)         run_cli history ;;
        t|top|leaders|leaderboard) run_cli leaderboard ;;
        q|quit|exit|back|'') break ;;
        *)                 cr '  Unrecognised choice.' ;;
    esac
done

printf '\033[2J\033[H'
cr 'Thanks for playing. Returning to BinkTerm...'
# EXIT trap wipes the session credential directory
exit 0
