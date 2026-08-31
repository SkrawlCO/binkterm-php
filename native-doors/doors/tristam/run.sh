#!/bin/bash
#
# Tristam Island - Interactive Fiction Native Door wrapper for BinktermPHP.
#
# This is the ONLY integration code. It is deliberately tiny and transparent:
#
#   * requires an authenticated BBS identity (numeric DOOR_USER_NUMBER);
#     this door does not permit anonymous / guest play
#   * roots every mutable dfrotz file operation (in-game SAVE / RESTORE /
#     SCRIPT / RECORDING) inside the caller's private per-user $DOOR_HOME,
#     using the interpreter's own documented "-R <path>" restricted-I/O mode
#   * hands the process lifecycle straight to the existing Native Door PTY
#     bridge via exec
#
# No shell escapes, menus, launchers, databases, HTTP/API calls, credit
# handling, or identity duplication. Usernames are never used as filesystem
# keys - only the numeric user id, via $DOOR_HOME.
#
# The Z-machine story file (story/tristam-en.z3) is read-only third-party
# content. See README.md for provenance, authorship and licensing of both
# the story and the Frotz interpreter. BinktermPHP platform source is not
# modified by this door.

set -eu

here="$(cd -- "$(dirname -- "$0")" && pwd)"
story="${here}/story/tristam-en.z3"

press_any_key_exit() {
    # $1 = exit code, remaining args = message lines
    local code="$1"; shift
    printf '\r\n'
    local line
    for line in "$@"; do
        printf '  %s\r\n' "$line"
    done
    printf '\r\n  Press any key to exit...'
    IFS= read -r -s -n 1 -t 30 _ || true
    printf '\r\n'
    exit "$code"
}

# --- Identity: registered members only -------------------------------------
# DOOR_USER_NUMBER is the numeric BBS user id (users.id). It is always set for
# an authenticated launch. The door's config leaves anonymous access disabled,
# so the guest launch path is already refused upstream; this is belt-and-braces.
case "${DOOR_USER_NUMBER:-}" in
    ''|*[!0-9]*|0)
        press_any_key_exit 1 \
            "Tristam Island is available to registered members only." \
            "Please sign in and launch it again."
        ;;
esac

# --- Per-user private save area -------------------------------------------
# $DOOR_HOME == data/users/<DOOR_USER_NUMBER>/tristam - a durable, per-user,
# per-door directory the bridge creates before launch. Keep this story's
# saves/transcripts in their own subdirectory for clarity.
: "${DOOR_HOME:?DOOR_HOME is not set}"
save_dir="${DOOR_HOME}/saves"
mkdir -p -- "${save_dir}"

# --- Preflight ------------------------------------------------------------
dfrotz_bin=""
if command -v dfrotz >/dev/null 2>&1; then
    dfrotz_bin="$(command -v dfrotz)"
elif [ -x /usr/games/dfrotz ]; then
    dfrotz_bin="/usr/games/dfrotz"
elif [ -x /usr/bin/dfrotz ]; then
    dfrotz_bin="/usr/bin/dfrotz"
fi

if [ -z "${dfrotz_bin}" ]; then
    press_any_key_exit 1 \
        "Tristam Island is not available right now." \
        "The Frotz Z-machine interpreter (Debian/Ubuntu package: frotz) is" \
        "not installed on this system. Please contact the sysop."
fi

if [ ! -r "${story}" ]; then
    press_any_key_exit 1 \
        "Tristam Island's story file is missing or unreadable." \
        "Please contact the sysop."
fi

# --- Launch -------------------------------------------------------------
#   -R <dir>  confine ALL interpreter read/write (SAVE / RESTORE / SCRIPT /
#             RECORDING) to <dir> and nowhere else  [dfrotz(6)]
#   -m        suppress MORE prompts - the BBS terminal does its own scrolling
#
# Text width is left to the PTY (dfrotz reads it from the terminal); no ANSI
# markup is requested, so output is plain UTF-8 text.
exec "${dfrotz_bin}" -R "${save_dir}" -m "${story}"
