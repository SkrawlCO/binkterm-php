#!/bin/bash
#
# Galactic Bloodshed NativeDoor launcher (Crossroads Experience candidate,
# Web + Telnet surfaces -- see the parent slice reports under
# docs/Crossroads/galactic-bloodshed-backend/ for full architecture/history).
#
# Thin wrapper around gb_launcher.py, itself a thin orchestrator: it never
# reimplements the game or the client -- gb-client.py + gb_client/ in this
# same directory are the OFFICIAL upstream Python client
# (kaladron/galactic-bloodshed pin d575334ec49a6bd387587acb968ba638d5cc98d1),
# vendored unmodified.
#
#   invoked by the dosbox-bridge NativeAdapter as:
#     /bin/bash launch-galactic-bloodshed.sh "{user_number}"
#
# {user_number} / DOOR_USER_NUMBER is door_sessions.user_id -- the
# authenticated BinkTerm caller, set server-side, not caller data.
#
set -u
set -o pipefail
# Deliberately NO `set -x` -- see launch-chessmata.sh's identical rule:
# tracing would risk printing a path that itself could leak alongside a
# future careless edit. Keep it off.

readonly SELF_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

# Fixed in production. GB_* overrides exist only so this script and
# gb_launcher.py can be exercised outside the real NativeDoor runtime
# (disposable proofs, tests) -- never set in the real NativeDoor environment.
export GB_APP_ROOT="${GB_APP_ROOT:-/var/www/html}"
export GB_PHP_BIN="${GB_PHP_BIN:-php}"
export GB_CLIENT_PY="${GB_CLIENT_PY:-${SELF_DIR}/gb-client.py}"
export GB_PROVISIOND_SOCKET="${GB_PROVISIOND_SOCKET:-/run/gb-provisiond/gb-provisiond.sock}"
export GB_PROVISIOND_TOKEN_FILE="${GB_PROVISIOND_TOKEN_FILE:-/run/secrets/galactic_bloodshed_provisiond_token}"
export GALACTICBLOODSHED_HOST="${GALACTICBLOODSHED_HOST:-galactic-bloodshed}"
export GALACTICBLOODSHED_PORT="${GALACTICBLOODSHED_PORT:-2010}"

user_id="${1:-${DOOR_USER_NUMBER:-0}}"
if [[ ! "$user_id" =~ ^[1-9][0-9]*$ ]] || (( user_id > 2147483647 )); then
    printf '\r\n'
    printf 'Galactic Bloodshed is temporarily unavailable. Please try again later.\r\n'
    exit 1
fi
export DOOR_USER_NUMBER="$user_id"

exec python3 "${SELF_DIR}/gb_launcher.py"
