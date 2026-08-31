#!/bin/bash
#
# BCR Games Server -- remote Telnet gateway for BinktermPHP Crossroads.
#
# This wrapper is the ENTIRE integration. It opens an ordinary, anonymous
# Telnet connection to the publicly advertised BCR endpoint and hands the
# caller straight to BCR's own Login / Create Character screen.
#
# Black Country Rock (Shooter Jennings) owns everything past the endpoint:
# the games (From Here To Eternity, Freedom Train, 1NS0MN1A), the service,
# BCR accounts and characters, all BCR content, and all BCR branding. L33TEST
# owns only this wrapper and the manifest beside it.
#
#   telnet -E -K bcrgames.com 31337
#     -E   disable the escape character  -> the caller can never break out to
#          a local "telnet>" command prompt
#     -K   no automatic login            -> no .netrc / TELNET_USER; nothing
#          about the BinkTerm user is offered to BCR
#
# NOT sent to BCR: BinkTerm username, real name, user id, password, drop-file
# data, or any other identity. BCR performs its own authentication.
#
# This wrapper does NOT parse, scrape, transcribe, or log any terminal input
# or output. One PTY / one outbound Telnet connection per caller; the
# BinktermPHP native-door bridge owns the process lifecycle via exec.

set -eu

# The approved public endpoint, hardcoded. Never taken from caller input.
BCR_HOST='bcrgames.com'
BCR_PORT='31337'

# Optional sysop-only override for a non-standard telnet client path
# (mirrors the pubterm door). Not caller-controlled.
TELNET_BIN="${BCR_TELNET_BIN:-telnet}"

if ! command -v "$TELNET_BIN" >/dev/null 2>&1; then
    printf '\r\n'
    printf '  BCR Games Server is not available right now.\r\n'
    printf '\r\n'
    printf "  The 'telnet' client is not installed on this system, so the\r\n"
    printf '  connection to bcrgames.com cannot be opened.\r\n'
    printf '\r\n'
    printf '    Debian/Ubuntu:  apt-get install -y telnet\r\n'
    printf '    Alpine:         apk add busybox-extras\r\n'
    printf '\r\n'
    printf '  Please contact the sysop.\r\n'
    printf '\r\n'
    printf '  Press any key to exit...'
    IFS= read -r -s -n 1 -t 30 _ || true
    printf '\r\n'
    exit 1
fi

exec "$TELNET_BIN" -E -K "$BCR_HOST" "$BCR_PORT"
