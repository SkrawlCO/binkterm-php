#!/bin/bash
#
# ascii-royale managed arena — privileged lifecycle wrapper
# (Crossroads Experience #2, M4 Slice 1 — Managed Arena Runtime)
#
# This is the REFERENCE COPY that is tracked in git. The deployed copy lives
# OUTSIDE the repo at /var/lib/ascii-royale/bin/ascii-royale-arena.sh and is
# run by supervisord as `[program:ascii-royale-arena]` (user=root).
#
# ---------------------------------------------------------------------------
# WHY THIS RUNS AS ROOT (and nothing else in the arena does)
# ---------------------------------------------------------------------------
# The committed M3 launcher (native-doors/doors/ascii-royale-m3/
# launch-ascii-royale.sh, unchanged) only trusts an endpoint record that is a
# root:root 0640 regular file inside a root:root 0750 directory. So the
# process that publishes and refreshes that record must be root.
#
# This wrapper therefore does exactly four privileged things and nothing else:
#   1. establish + maintain the root-owned endpoint channel
#   2. launch the actual `ascii-royale serve` arena as the dedicated,
#      unprivileged `ascii-royale` account (never as root)
#   3. supervise that one child by exact PID
#   4. remove the channel (and any temp files) when it exits
#
# It takes NO positional arguments. Production passes NO environment overrides
# (every override below exists only for the tracked black-box harness and is
# strictly validated). It never eval's, never derives an executable path from
# unvalidated input, and never prints or logs the EndpointId — only upstream
# `serve` prints it, to the private arena log (see AsciiRoyaleProduction.md).
# ---------------------------------------------------------------------------

set -euo pipefail
IFS=$' \t\n'
umask 027
export LC_ALL=C

# --- fixed production policy (NOT overridable) ------------------------------
readonly PINNED_SHA='ac7d9771dfd788b278427db619e43989d4317029'
readonly RECORD_VERSION='1'
readonly BOTS='9'
readonly AUTO_START_SECS='20'
readonly AUTO_RESET_SECS='12'
readonly TICKET_RE='^[0-9a-f]{64}$'

# --- strictly-validated overrides (production sets none of these) ----------
# Mirrors how the committed M3 launcher itself takes ASCII_ROYALE_M3_* env
# overrides: `${VAR:-default}` then validate-or-die.
ARENA_ROOT="${ASCII_ROYALE_ARENA_ROOT:-/var/lib/ascii-royale}"
RUN_USER="${ASCII_ROYALE_ARENA_RUN_USER:-ascii-royale}"
EXPECT_SHA256="${ASCII_ROYALE_ARENA_EXPECT_SHA256:-b7d59c4083e4b2ef3664be57145a70bfbb178db170efbb989e2580fe56d8d84e}"
STARTUP_TIMEOUT="${ASCII_ROYALE_ARENA_STARTUP_TIMEOUT:-60}"
HEARTBEAT_SECS="${ASCII_ROYALE_ARENA_HEARTBEAT_SECS:-5}"

log() { printf 'ascii-royale-arena: %s\n' "$*"; }
die() { printf 'ascii-royale-arena: FATAL: %s\n' "$*" >&2; exit 1; }

# --- validate overrides --------------------------------------------------
[[ $ARENA_ROOT == /* && $ARENA_ROOT != / ]]        || die "ARENA_ROOT must be an absolute path below /"
[[ -d $ARENA_ROOT && ! -L $ARENA_ROOT ]]           || die "ARENA_ROOT is not a real directory: $ARENA_ROOT"
[[ $RUN_USER =~ ^[a-z_][a-z0-9_-]{0,31}$ ]]        || die "RUN_USER is not a valid account name"
[[ $EXPECT_SHA256 =~ ^[0-9a-f]{64}$ ]]             || die "EXPECT_SHA256 is not a 64-hex digest"
[[ $STARTUP_TIMEOUT =~ ^[0-9]{1,4}$ && $STARTUP_TIMEOUT -ge 5 ]] || die "STARTUP_TIMEOUT out of range"
[[ $HEARTBEAT_SECS  =~ ^[0-9]{1,3}$ && $HEARTBEAT_SECS -ge 1 && $HEARTBEAT_SECS -le 15 ]] || die "HEARTBEAT_SECS out of range (launcher stale gate is 15s)"

readonly ARENA_ROOT RUN_USER EXPECT_SHA256 STARTUP_TIMEOUT HEARTBEAT_SECS

# --- fixed trusted paths, all derived from ARENA_ROOT -------------------
readonly BIN="$ARENA_ROOT/$PINNED_SHA/ascii-royale"   # == M3 launcher's $runtime_root/$PINNED_SHA/ascii-royale
readonly ALSA="$ARENA_ROOT/alsa-null.conf"            # == M3 launcher's $runtime_root/alsa-null.conf
readonly HOME_DIR="$ARENA_ROOT/home"
readonly TMP_DIR="$ARENA_ROOT/tmp"
readonly RUN_DIR="$ARENA_ROOT/run"
readonly PRIV_DIR="$RUN_DIR/private"                  # arena-writable; holds the raw ticket handoff
readonly TICKET_RAW="$PRIV_DIR/ticket.raw"
readonly CHANNEL_DIR="$RUN_DIR/ascii-royale-m3"       # == dirname of ASCII_ROYALE_M3_CHANNEL
readonly CHANNEL="$CHANNEL_DIR/endpoint-id"
readonly LOG_DIR="$ARENA_ROOT/log"                    # supervisord writes arena.{out,err}.log here

CHILD_PID=''
ENDPOINT_ID=''        # held in memory only; never printed, never in argv
HOST_GENERATION=''
CLEANED=0

# ---------------------------------------------------------------------------
# cleanup — removes BOTH the channel and any .endpoint-id.* temp files
# (the M3 heartbeat.sh trap removed only the temp files — fixed here) and
# stops the exact child. Idempotent.
# ---------------------------------------------------------------------------
cleanup() {
    [[ $CLEANED -eq 0 ]] || return 0
    CLEANED=1
    trap - TERM INT EXIT
    if [[ -n $CHILD_PID ]] && kill -0 "$CHILD_PID" 2>/dev/null; then
        kill -TERM "$CHILD_PID" 2>/dev/null || true
        for _ in $(seq 1 20); do
            kill -0 "$CHILD_PID" 2>/dev/null || break
            sleep 0.5
        done
        kill -KILL "$CHILD_PID" 2>/dev/null || true
        wait "$CHILD_PID" 2>/dev/null || true
    fi
    rm -f -- "$CHANNEL" 2>/dev/null || true
    rm -f -- "$CHANNEL_DIR"/.endpoint-id.* 2>/dev/null || true
    log "stopped; endpoint channel removed"
}
trap 'cleanup; exit 143' TERM
trap 'cleanup; exit 130' INT
trap cleanup EXIT

# ---------------------------------------------------------------------------
# harden_logs — the supervisor program's own log files (arena.{out,err}.log)
# carry the EndpointId that unmodified upstream `serve` prints. supervisord
# creates/rotates them with its process umask (0644); force them private at
# BOTH levels: dir 0750, files 0640 root:$RUN_USER. Run in preflight and on
# every heartbeat so a mid-run rotation is re-tightened within HEARTBEAT_SECS.
# Best-effort: a no-op when there is no LOG_DIR (e.g. the black-box harness).
# ---------------------------------------------------------------------------
harden_logs() {
    [[ -d $LOG_DIR && ! -L $LOG_DIR ]] || return 0
    chmod 0750 -- "$LOG_DIR" 2>/dev/null || true
    shopt -s nullglob
    local f
    for f in "$LOG_DIR"/arena.*.log "$LOG_DIR"/arena.*.log.*; do
        [[ -f $f && ! -L $f ]] || continue
        chown "0:$RUN_USER" -- "$f" 2>/dev/null || true
        chmod 0640 -- "$f" 2>/dev/null || true
    done
    shopt -u nullglob
}

# ---------------------------------------------------------------------------
# preflight — refuse to run on anything unsafe
# ---------------------------------------------------------------------------
[[ $(id -u) -eq 0 ]] || die "must run as root (publishes a root:root channel, drops privileges for the arena)"

run_uid="$(id -u "$RUN_USER" 2>/dev/null || true)"
[[ -n $run_uid ]]    || die "service account '$RUN_USER' does not exist"
[[ $run_uid -ne 0 ]] || die "service account '$RUN_USER' must not be uid 0"

[[ -e $BIN && ! -L $BIN ]] || die "arena binary missing or a symlink: $BIN"
[[ -f $BIN && -x $BIN ]]   || die "arena binary is not a regular executable: $BIN"
[[ -z "$(find "$BIN" -perm /022 -print -prune 2>/dev/null)" ]] || die "arena binary is group/other-writable: $BIN"
actual_sha="$(sha256sum -- "$BIN" | cut -d' ' -f1)"
[[ $actual_sha == "$EXPECT_SHA256" ]] || die "arena binary SHA-256 mismatch: got $actual_sha want $EXPECT_SHA256"

[[ -f $ALSA && ! -L $ALSA ]] || die "ALSA null config missing or a symlink: $ALSA"

for d in "$HOME_DIR" "$TMP_DIR"; do
    [[ -d $d && ! -L $d ]] || die "runtime dir missing or a symlink: $d"
    [[ $(stat -c '%U' -- "$d") == "$RUN_USER" ]] || die "runtime dir not owned by $RUN_USER: $d"
done

mkdir -p -- "$RUN_DIR"
[[ ! -L $RUN_DIR ]] || die "run dir is a symlink: $RUN_DIR"
chown 0:0 -- "$RUN_DIR"; chmod 0755 -- "$RUN_DIR"

install -d -o "$RUN_USER" -g "$RUN_USER" -m 0700 -- "$PRIV_DIR"
[[ ! -L $PRIV_DIR ]] || die "private dir is a symlink: $PRIV_DIR"

[[ ! -L $CHANNEL_DIR ]] || die "channel dir is a symlink: $CHANNEL_DIR"
mkdir -p -- "$CHANNEL_DIR"
chown 0:0 -- "$CHANNEL_DIR"; chmod 0750 -- "$CHANNEL_DIR"

# never inherit a stale record from a previous arena — and do NOT publish yet
rm -f -- "$CHANNEL" "$CHANNEL_DIR"/.endpoint-id.* "$TICKET_RAW" 2>/dev/null || true

harden_logs

# ---------------------------------------------------------------------------
# launch the arena as the unprivileged account (never root)
# ---------------------------------------------------------------------------
HOST_GENERATION="g$(date -u +%Y%m%dT%H%M%SZ)-$(head -c6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
[[ $HOST_GENERATION =~ ^[A-Za-z0-9._-]{1,64}$ ]] || die "internal: bad host_generation"
readonly HOST_GENERATION
log "starting arena (generation $HOST_GENERATION, user $RUN_USER, bots $BOTS)"

# setpriv and `env -i` both exec directly (no fork), so $! is the real
# `ascii-royale` PID. `env -i` gives the child a pristine environment.
setpriv --reuid "$RUN_USER" --regid "$RUN_USER" --clear-groups --no-new-privs -- \
    env -i \
        HOME="$HOME_DIR" \
        TMPDIR="$TMP_DIR" \
        ALSA_CONFIG_PATH="$ALSA" \
        PATH=/usr/bin:/bin \
        "$BIN" serve \
            --bots "$BOTS" \
            --auto-start-secs "$AUTO_START_SECS" \
            --auto-reset-secs "$AUTO_RESET_SECS" \
            --ticket-file "$TICKET_RAW" &
CHILD_PID=$!
readonly CHILD_PID
log "arena child pid $CHILD_PID"

# ---------------------------------------------------------------------------
# wait for a valid ticket (fail closed on timeout or early child exit)
# ---------------------------------------------------------------------------
deadline=$(( $(date +%s) + STARTUP_TIMEOUT ))
while :; do
    kill -0 "$CHILD_PID" 2>/dev/null || die "arena exited during startup (before producing a valid ticket)"
    if [[ -f $TICKET_RAW && ! -L $TICKET_RAW ]]; then
        candidate="$(head -c 200 -- "$TICKET_RAW" 2>/dev/null | tr -d ' \t\r\n' || true)"
        if [[ $candidate =~ $TICKET_RE ]]; then
            ENDPOINT_ID="$candidate"
            break
        fi
    fi
    [[ $(date +%s) -lt $deadline ]] || die "arena did not produce a valid ticket within ${STARTUP_TIMEOUT}s"
    sleep 0.5
done
readonly ENDPOINT_ID
log "ticket acquired; publishing endpoint channel"

# ---------------------------------------------------------------------------
# atomic publish + heartbeat
# ---------------------------------------------------------------------------
publish() {
    local now tmp
    now=$(date +%s)
    [[ -d $CHANNEL_DIR && ! -L $CHANNEL_DIR ]] || die "channel dir vanished or became a symlink"
    chown 0:0 -- "$CHANNEL_DIR"; chmod 0750 -- "$CHANNEL_DIR"
    tmp="$(mktemp -- "$CHANNEL_DIR/.endpoint-id.XXXXXX")"
    {
        printf 'version=%s\n'         "$RECORD_VERSION"
        printf 'pinned_sha=%s\n'      "$PINNED_SHA"
        printf 'updated_unix=%s\n'    "$now"
        printf 'host_generation=%s\n' "$HOST_GENERATION"
        printf 'endpoint_id=%s\n'     "$ENDPOINT_ID"
    } >"$tmp"
    chown 0:0 -- "$tmp"
    chmod 0640 -- "$tmp"
    mv -f -- "$tmp" "$CHANNEL"
}

publish
harden_logs
log "endpoint channel live; heartbeat every ${HEARTBEAT_SECS}s"

while kill -0 "$CHILD_PID" 2>/dev/null; do
    sleep "$HEARTBEAT_SECS" &
    wait $! 2>/dev/null || true      # a trapped signal interrupts this -> cleanup+exit
    kill -0 "$CHILD_PID" 2>/dev/null || break
    publish
    harden_logs                     # re-tighten a supervisord-rotated 0644 log within HEARTBEAT_SECS
done

die "arena process $CHILD_PID exited; failing closed for supervisor restart"
