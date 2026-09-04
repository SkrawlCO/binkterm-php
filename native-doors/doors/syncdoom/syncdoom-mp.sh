#!/bin/bash
#
# SyncDOOM native-door wrapper -- BinktermPHP Crossroads.
#
# This wrapper is the SyncDOOM-owned integration layer between the generic
# NativeDoor bridge and the SyncDOOM engine. It offers:
#
#   [S] the existing, already-accepted single-player launch, unchanged;
#   [C] Create a 2-player co-op match: spawn a detached dedicated server,
#       confirm its lobby registry entry, then hand this caller (as the
#       netgame controller) into SyncDOOM's own native waiting room.
#
# Join/Browse, deathmatch selection, altdeath, 3-4 players, skill choice and
# map/warp are NOT implemented here -- see docs/proposals or the design recon
# that preceded this slice. This is plumbing, not the finished lobby.
#
# The final operation on every path is a shell `exec` into the real syncdoom
# binary: no relay layer remains, the caller's PTY stays direct, and sixel
# stays on the already-proven byte-transparent NativeDoor transport.
#
# Caller identity comes from the DOOR_* environment variables the bridge
# already exports (scripts/dosbox-bridge/emulator-adapters.js
# NativeAdapter.launch()) -- never from manifest string interpolation -- and
# every caller-controlled value is kept as its own quoted argv entry. No
# eval, no single interpolated command string.

set -eu

DOOR_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)"
SYNCDOOM_BIN="$DOOR_DIR/syncdoom"
IWAD='freedoom2.wad'

# The shared, all-callers-visible multiplayer registry. Doors live at
# <approot>/native-doors/doors/<door_id>/, so the app root is three levels up.
APP_ROOT="$(cd "$DOOR_DIR/../../.." && pwd)"
GAMES_DIR="$APP_ROOT/data/run/syncdoom/games"

# How long to wait for the detached server's registry entry to appear before
# giving up. Small and bounded -- never indefinite.
REGISTRY_WAIT_TRIES=20
REGISTRY_WAIT_INTERVAL='0.1'

fail() {
    printf '\r\n  %s\r\n\r\n' "$1"
    printf '  Press any key to continue...'
    IFS= read -r -s -n 1 -t 30 _ || true
    printf '\r\n'
}

require_var() {
    # $1 = variable name, $2 = friendly label. Uses bash indirect expansion
    # (${!name}), never eval, to test an arbitrary-named variable.
    if [ -z "${!1:-}" ]; then
        fail "SyncDOOM cannot start: $2 is not available for this session."
        exit 1
    fi
}

# A registry `host =` display value derived from the caller's handle, reduced
# to a safe charset. mp_write_registry() (mp_server.c) writes this verbatim
# into a plain-text .ini with no escaping, so it must never carry embedded
# newlines or `=`/`[` syntax that could corrupt another field. This does not
# affect -name (sent over the network protocol, not written to a shared file).
safe_host_id() {
    printf '%s' "${1:-player}" | tr -c 'A-Za-z0-9_-' '_' | cut -c1-32
}

# Verify a PID is really the dedicated server this invocation just spawned
# (never a broad kill) before terminating it.
kill_if_our_dedicated_server() {
    pid="$1"
    if kill -0 "$pid" 2>/dev/null; then
        cmdline=$(ps -o cmd= -p "$pid" 2>/dev/null || true)
        case "$cmdline" in
            *syncdoom*-dedicated*) kill -TERM "$pid" 2>/dev/null || true ;;
        esac
    fi
}

show_menu() {
    printf '\r\n'
    printf '  SYNCDOOM\r\n'
    printf '\r\n'
    printf '  [S] Single Player\r\n'
    printf '  [C] Create 2-Player Co-op\r\n'
    printf '  [Q] Return\r\n'
    printf '\r\n'
    printf '  Your choice: '
}

launch_single_player() {
    require_var DOOR_DROPFILE 'your session drop file'
    require_var DOOR_HOME 'your player data directory'

    exec "$SYNCDOOM_BIN" "$DOOR_DROPFILE" \
        -home "$DOOR_HOME" \
        -iwad "$IWAD" \
        -sixel 1
}

launch_create_coop() {
    require_var DOOR_DROPFILE 'your session drop file'
    require_var DOOR_HOME 'your player data directory'
    require_var DOOR_USER_NAME 'your caller name'

    mkdir -p "$GAMES_DIR"

    host_id=$(safe_host_id "$DOOR_USER_NAME")

    # mp_write_registry() (mp_server.c) builds its filename as
    # "<gamesdir><hostid>-<port>.ini" -- a raw string concatenation with no
    # separator inserted -- so -gamesdir MUST carry a trailing slash or the
    # entry lands one directory up, glued onto the directory's own name.
    set +e
    spawn_out=$("$SYNCDOOM_BIN" -spawnserver \
        -maxplayers 2 \
        -gamesdir "$GAMES_DIR/" \
        -host "$host_id" \
        -wadset freedoom2 \
        -gamemode coop \
        -bindaddr 127.0.0.1 \
        -advertise 127.0.0.1 2>&1)
    spawn_status=$?
    set -e

    if [ "$spawn_status" -ne 0 ]; then
        fail "Could not start a co-op server right now. Please try again shortly."
        exit 1
    fi

    # Expected stdout: "<pid> <port>" -- one line, two numeric fields.
    spawn_pid=$(printf '%s\n' "$spawn_out" | awk '{print $1}')
    spawn_port=$(printf '%s\n' "$spawn_out" | awk '{print $2}')

    case "$spawn_pid" in
        ''|*[!0-9]*) fail "Could not start a co-op server right now. Please try again shortly."; exit 1 ;;
    esac
    case "$spawn_port" in
        ''|*[!0-9]*) fail "Could not start a co-op server right now. Please try again shortly."; exit 1 ;;
    esac
    if [ "$spawn_port" -lt 1 ] || [ "$spawn_port" -gt 65535 ]; then
        fail "Could not start a co-op server right now. Please try again shortly."
        exit 1
    fi

    # Bounded wait for the server's own registry entry (<hostid>-<port>.ini) --
    # tiny scheduling latency between spawn and the server's first write.
    registry_file=''
    tries=0
    while [ "$tries" -lt "$REGISTRY_WAIT_TRIES" ]; do
        for f in "$GAMES_DIR"/*"-$spawn_port.ini"; do
            if [ -e "$f" ]; then
                registry_file="$f"
                break
            fi
        done
        [ -n "$registry_file" ] && break
        sleep "$REGISTRY_WAIT_INTERVAL"
        tries=$((tries + 1))
    done

    if [ -z "$registry_file" ]; then
        kill_if_our_dedicated_server "$spawn_pid"
        fail "The co-op server did not come up in time. Please try again."
        exit 1
    fi

    # Hand off: this caller becomes the netgame controller. Co-op only in
    # this slice -- no -deathmatch/-altdeath flag is ever passed here.
    exec "$SYNCDOOM_BIN" "$DOOR_DROPFILE" \
        -connect "127.0.0.1:$spawn_port" \
        -players 2 \
        -skill 3 \
        -home "$DOOR_HOME" \
        -iwad "$IWAD" \
        -name "$DOOR_USER_NAME" \
        -sixel 1
}

while true; do
    show_menu
    if ! IFS= read -r -s -n 1 choice; then
        # EOF / disconnect at the menu -- return cleanly, nothing was spawned.
        exit 0
    fi
    printf '\r\n'
    case "$choice" in
        [Ss]) launch_single_player ;;
        [Cc]) launch_create_coop ;;
        [Qq]) exit 0 ;;
        *) : ;; # unrecognized key -- redraw the menu
    esac
done
