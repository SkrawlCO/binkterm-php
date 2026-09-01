#!/bin/bash
#
# Test double for `ascii-royale serve`. Reproduces ONLY the contract the
# arena wrapper depends on:
#   --ticket-file <path>   : written with a 64-hex ticket once "online"
#   stdout                 : prints `[arena] ticket: <hex>` (like upstream)
#   lifetime               : runs until SIGTERM, then exits 0
#
# The wrapper launches this via `env -i` (a pristine environment), so the
# scenario is selected NOT by an env var but by a `mode` file the harness
# seeds next to the --ticket-file path:
#   normal   (default) become ready after ~1s, then run until TERM
#   no_ticket          never write / print a ticket; run until TERM
#   crash              become ready, then exit 1 after ~3s (arena crash)
#
# It also records the uid it runs as, so the harness can prove the wrapper
# dropped privileges.
#
set -euo pipefail

TICKET_FILE=''
while (( $# )); do
    case "$1" in
        --ticket-file) TICKET_FILE="$2"; shift 2 ;;
        --bots|--auto-start-secs|--auto-reset-secs) shift 2 ;;
        *) shift ;;
    esac
done

MODE=normal
if [[ -n "$TICKET_FILE" ]]; then
    dir="$(dirname "$TICKET_FILE")"
    [[ -r "$dir/mode" ]] && MODE="$(tr -dc 'a-z_' < "$dir/mode")"
    printf '%s\n' "$(id -u)" > "$dir/served.uid" 2>/dev/null || true
fi

# a fixed, obviously-fake 64-hex value — never a real EndpointId
TICKET='0000000000000000000000000000000000000000000000000000000000000abc'

trap 'exit 0' TERM INT

if [[ "$MODE" == "no_ticket" ]]; then
    while :; do sleep 1; done
fi

sleep 1
[[ -n "$TICKET_FILE" ]] && printf '%s' "$TICKET" > "$TICKET_FILE"
printf '[arena] ticket: %s\n' "$TICKET"
printf '[arena] join with: ascii-royale join %s\n' "$TICKET"

if [[ "$MODE" == "crash" ]]; then
    sleep 3
    echo "[arena] simulated crash" >&2
    exit 1
fi

while :; do sleep 1; done
