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
#   [J] Join a game: list other callers' currently-joinable co-op lobbies
#       (read directly from the shared registry directory -- no database,
#       no separate service) and, on selection, hand this caller into the
#       chosen match as a client.
#
# Deathmatch selection, altdeath, 3-4 players, skill choice and map/warp are
# NOT implemented here -- see docs/proposals or the design recon that
# preceded this slice. This is plumbing, not the finished lobby.
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

# A lobby entry's heartbeat (mp_write_registry(), mp_server.c) is refreshed
# roughly every 3s while it is joinable, so this is generous against normal
# scheduling jitter while still excluding an entry whose server has died
# without cleaning up.
LOBBY_STALE_SECS=15

# Join is restricted to lobbies matching exactly what this wrapper's own
# Create path produces today -- a 2-player co-op match. This is a Join-side
# display/eligibility filter only: it does not touch, delete, or modify any
# registry file that falls outside it.
JOIN_MAXPLAYERS=2
JOIN_MODE='coop'

# Enumerated-lobby state, populated by enumerate_joinable_games() and read by
# join_game(). Declared up front so `set -u` never sees them unset.
declare -a join_files=()
declare -a join_hosts=()
declare -a join_players=()
declare -a join_maxplayers=()

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
    printf '  [J] Join Game\r\n'
    printf '  [Q] Return\r\n'
    printf '\r\n'
    printf '  Your choice: '
}

# Reduce arbitrary registry text to a safe, single-line, printable-ASCII
# display value. Registry files are data written by any match's server, not
# just this wrapper's own Create path, so a display string is never trusted:
# strip everything outside printable ASCII (this drops control bytes, ESC,
# raw UTF-8 lead/continuation bytes -- anything that could carry a terminal
# escape sequence) and cap the length.
sanitize_display() {
    local s
    s=$(printf '%s' "${1:-}" | tr -cd '\40-\176')
    s="${s:0:24}"
    printf '%s' "${s:-Someone}"
}

# Read a registry .ini ($1) as pure data -- never sourced, never eval'd.
# Splits each line on the first '=' and keeps only recognized keys in the
# global associative array `reg` (reset on every call). Unknown keys and
# malformed lines are silently ignored; values are stored verbatim as data
# and are never re-interpreted as shell syntax. Returns 1 if $1 is not a
# plain regular file (symlinks rejected) or can't be read.
parse_registry_file() {
    local f="$1" line key value
    declare -gA reg=()

    if [ -L "$f" ] || [ ! -f "$f" ]; then
        return 1
    fi

    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in
            *'='*)
                key="${line%%=*}"
                value="${line#*=}"
                key="${key#"${key%%[![:space:]]*}"}"; key="${key%"${key##*[![:space:]]}"}"
                value="${value#"${value%%[![:space:]]*}"}"; value="${value%"${value##*[![:space:]]}"}"
                case "$key" in
                    host | wadset | mode | addr | port | hostid | players | maxplayers | status | pid | heartbeat)
                        reg["$key"]="$value"
                        ;;
                    *) : ;;
                esac
                ;;
        esac
    done < "$f" || true

    return 0
}

# Validate registry file $1 as a currently-joinable lobby for this slice. On
# success sets rv_host/rv_players/rv_maxplayers/rv_port from a FRESH read of
# $1 and returns 0; on any failure returns 1 and rv_* must not be used. Used
# both to build the Join list and, unchanged, to re-check the caller's exact
# selection immediately before exec (the TOCTOU guard) -- the same rules
# apply both times because it is the same function.
validate_joinable() {
    local f="$1" now status players maxplayers port addr mode heartbeat

    parse_registry_file "$f" || return 1

    status="${reg[status]:-}"
    players="${reg[players]:-}"
    maxplayers="${reg[maxplayers]:-}"
    port="${reg[port]:-}"
    addr="${reg[addr]:-}"
    mode="${reg[mode]:-}"
    heartbeat="${reg[heartbeat]:-}"

    [ "$status" = "lobby" ] || return 1

    case "$players" in '' | *[!0-9]*) return 1 ;; esac
    case "$maxplayers" in '' | *[!0-9]*) return 1 ;; esac
    [ "$maxplayers" -eq "$JOIN_MAXPLAYERS" ] || return 1
    [ "$players" -lt "$maxplayers" ] || return 1

    case "$port" in '' | *[!0-9]*) return 1 ;; esac
    [ "$port" -ge 1 ] && [ "$port" -le 65535 ] || return 1

    # Loopback only -- this slice never connects anywhere else, regardless of
    # what any registry file's addr field claims.
    [ "$addr" = "127.0.0.1" ] || return 1

    [ "$mode" = "$JOIN_MODE" ] || return 1

    case "$heartbeat" in '' | *[!0-9]*) return 1 ;; esac
    now=$(date +%s)
    [ $((now - heartbeat)) -le "$LOBBY_STALE_SECS" ] || return 1

    rv_host="${reg[host]:-Someone}"
    rv_players="$players"
    rv_maxplayers="$maxplayers"
    rv_port="$port"
    return 0
}

# Populate join_files/join_hosts/join_players/join_maxplayers from every
# currently-valid lobby entry under GAMES_DIR. Display fields are sanitized
# here; join_files entries are real, already-glob-produced paths under the
# known games directory only -- nothing caller- or registry-supplied can
# redirect this to another file.
enumerate_joinable_games() {
    join_files=()
    join_hosts=()
    join_players=()
    join_maxplayers=()

    local f
    for f in "$GAMES_DIR"/*.ini; do
        [ -e "$f" ] || continue
        if validate_joinable "$f"; then
            join_files+=("$f")
            join_hosts+=("$(sanitize_display "$rv_host")")
            join_players+=("$rv_players")
            join_maxplayers+=("$rv_maxplayers")
        fi
    done
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

join_game() {
    require_var DOOR_DROPFILE 'your session drop file'
    require_var DOOR_HOME 'your player data directory'
    require_var DOOR_USER_NAME 'your caller name'

    enumerate_joinable_games

    if [ ${#join_files[@]} -eq 0 ]; then
        printf '\r\n  No games are waiting right now.\r\n\r\n'
        printf '  Press any key to continue...'
        IFS= read -r -s -n 1 -t 30 _ || true
        printf '\r\n'
        return 0
    fi

    printf '\r\n  SYNCDOOM -- JOIN GAME\r\n\r\n'
    local i=1 idx
    for idx in "${!join_files[@]}"; do
        printf '  %d. %s -- Co-op -- %s/%s players\r\n' \
            "$i" "${join_hosts[$idx]}" "${join_players[$idx]}" "${join_maxplayers[$idx]}"
        i=$((i + 1))
    done
    printf '\r\n  Selection (or Q to return): '

    # `read` returns nonzero on EOF even when it already captured a value
    # (e.g. input ending right after the digits with no trailing newline),
    # so check the captured value itself rather than read's exit status.
    local selection=''
    IFS= read -r selection || true
    printf '\r\n'
    if [ -z "$selection" ]; then
        return 0
    fi

    case "$selection" in
        [Qq]) return 0 ;;
    esac
    case "$selection" in
        '' | *[!0-9]*)
            printf '\r\n  Invalid selection.\r\n'
            return 0
            ;;
    esac
    if [ "$selection" -lt 1 ] || [ "$selection" -gt "${#join_files[@]}" ]; then
        printf '\r\n  Invalid selection.\r\n'
        return 0
    fi

    local chosen="${join_files[$((selection - 1))]}"

    # Time-of-check/time-of-use: the list above may already be stale by the
    # time the caller picks one, so re-validate this exact file from scratch
    # -- same function, same rules -- and connect only to what it says now.
    if ! validate_joinable "$chosen"; then
        fail "That game is no longer available."
        return 0
    fi

    # The joining client never reconstructs controller gameplay flags
    # (-deathmatch/-altdeath/-skill/-warp) -- those are negotiated from the
    # controller over the network at GAMESTART regardless of what a joiner
    # requests (proven: d_net.c / net_structrw.c). -players is tied to the
    # freshly-revalidated maxplayers, never hardcoded independently of it.
    exec "$SYNCDOOM_BIN" "$DOOR_DROPFILE" \
        -connect "127.0.0.1:$rv_port" \
        -players "$rv_maxplayers" \
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
        [Jj]) join_game ;;
        [Qq]) exit 0 ;;
        *) : ;; # unrecognized key -- redraw the menu
    esac
done
