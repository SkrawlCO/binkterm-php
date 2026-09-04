#!/bin/bash
#
# Tournament Trivia NativeDoor launcher (Crossroads Experience #5, Telnet surface).
#
# Thin L33TEST wrapper around the OFFICIAL upstream Tournament Trivia Door32
# client `triv32` (image-baked at /var/lib/tournament-trivia, built from the
# pinned upstream + pinned Synchronet OpenDoors SDK -- see
# ops/docker/tournament-trivia/README.md). It does NOT reimplement any game UI;
# the board, questions, chat and input are 100% the upstream client.
#
#   invoked by the dosbox-bridge NativeAdapter (cwd = this door's dir) as:
#     /bin/bash launch-tournament-trivia.sh "{user_number}" "{user_name}" "{node}"
#
#   with, in the environment: DOOR_USER_NUMBER, DOOR_USER_NAME, DOOR_NODE
#   (set server-side from the authenticated door_sessions row, not caller data).
#
# IDENTITY PATH: `triv32 -LOCAL -USERNAME <name> -NODE <n>` -- the legitimate
# upstream local mode proved end-to-end in M1. Not DOOR32.SYS: OpenDoors' Door32
# comm-handle path would need new BinkTerm<->PTY comm wiring (M1: `triv32 -D
# <door32.sys>` comm-type-0 produced no session), which is out of this slice's
# scope. `-LOCAL` gives OpenDoors direct console I/O on the caller PTY and still
# takes the REAL BinkTerm username + node from the NativeDoor boundary below.
#
set -u
export LC_ALL=C

# The client creates its own per-node output queue (/trvout<N>); the shared
# trivsrv (a DIFFERENT, unprivileged service account) must be able to write to
# it. The upstream source already asks mq_open() for 0666 -- clear the umask so
# that request is honoured and cross-account IPC works.
umask 000

RUNTIME_DIR="${TOURNAMENT_TRIVIA_RUNTIME_DIR:-/var/lib/tournament-trivia}"

cr()   { printf '%s\r\n' "$*"; }
fail() { cr ''; cr 'Tournament Trivia is temporarily unavailable. Please try again later.'; exit 1; }

# --- identity (from the NativeDoor boundary: positional arg, then env) --------
user_id="${1:-${DOOR_USER_NUMBER:-0}}"
raw_name="${2:-${DOOR_USER_NAME:-}}"
node="${3:-${DOOR_NODE:-0}}"

if [[ ! "$user_id" =~ ^[1-9][0-9]*$ ]] || (( user_id > 2147483647 )); then
    cr ''; cr 'Tournament Trivia needs a logged-in BinkTerm account. Please sign in and try again.'
    exit 1
fi

# node number -> a distinct triv32 node (1..999). Unique per concurrent BinkTerm
# session already; clamp defensively so it is always a small valid integer.
if [[ ! "$node" =~ ^[0-9]+$ ]] || (( node < 1 )) || (( node > 999 )); then
    node=$(( (user_id % 900) + 1 ))
fi

# username -> a safe single argv token for `-USERNAME`. Keep letters, digits and
# a few printable separators; collapse anything else. Never empty, <= 30 chars.
name=''
for (( i=0; i<${#raw_name} && ${#name}<30; i++ )); do
    ch="${raw_name:i:1}"
    case "$ch" in
        [A-Za-z0-9] ) name+="$ch" ;;
        [\ ._-]     ) name+="$ch" ;;
    esac
done
name="${name#"${name%%[![:space:]]*}"}"   # ltrim
name="${name%"${name##*[![:space:]]}"}"    # rtrim
[[ -n "$name" ]] || name="player${user_id}"

# --- run the upstream client on the caller PTY ------------------------------
# cwd = runtime dir so triv32 finds user.hlp / MENU.* and, if it posix_spawn()s
# a "trivsrv" (it does, unconditionally, relative to cwd), that extra copy hits
# the supervised server's flock and exit(0)s immediately -- the client then
# connects to the one shared trivsrv over /trvinput.
#
# Invoke with an ABSOLUTE argv[0]: OpenDoors' correctDirectory() rejects a
# "./name" argv[0] (strips it to "./" -> len 2 -> "Door directory invalid" ->
# the client exits). An absolute path strips cleanly to the runtime dir and it
# chdir()s there itself.
cd "$RUNTIME_DIR" || fail
[[ -x "$RUNTIME_DIR/triv32" ]] || fail

exec "$RUNTIME_DIR/triv32" -LOCAL -USERNAME "$name" -NODE "$node" -GRAPHICS
