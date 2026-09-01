#!/bin/bash
#
# Black-box regression for the arena wrapper (../runtime/ascii-royale-arena.sh).
#
# Runs AS ROOT inside a disposable binkterm-app container (see run-regression.sh).
# Uses ./fake-serve.sh as a stand-in for `ascii-royale serve` so the contract
# is proven hermetically — no network, no real binary, no touching the
# production /var/lib/ascii-royale or the running arena.
#
# Proves, against the EXACT committed wrapper:
#   1  normal start -> endpoint channel matches the M3 launcher contract
#   2  the arena child runs UNPRIVILEGED; the wrapper stays the only root proc
#   3  the wrapper's own log lines never contain the EndpointId
#      (upstream `serve`'s `[arena] ticket:` line legitimately does — that is
#       why the production log is private; see AsciiRoyaleProduction.md)
#   3b the wrapper tightens the supervisord log dir to 0750 and
#      arena.*.log to 0640 root:<service account>
#   4  the heartbeat keeps updated_unix + mtime fresh
#   5  SIGTERM removes the channel AND every .endpoint-id.* temp file
#   6  restart -> a NEW host_generation, a fresh valid channel
#   7  no valid ticket -> wrapper fails closed (non-zero), channel never created
#   8  arena crash -> wrapper fails closed, channel removed
#   9  binary SHA-256 mismatch -> wrapper refuses to start, channel never created
#
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WRAPPER="$(cd "$HERE/../runtime" && pwd)/ascii-royale-arena.sh"
FAKE="$HERE/fake-serve.sh"
FAKE_SHA="$(sha256sum "$FAKE" | cut -d' ' -f1)"
PIN_DIR="ac7d9771dfd788b278427db619e43989d4317029"

PASS=0 FAIL=0
ok() { PASS=$((PASS+1)); printf '  ok   %s\n' "$*"; }
no() { FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$*"; }
chk(){ if eval "$2"; then ok "$1"; else no "$1"; fi; }

command -v setpriv >/dev/null || { echo "setpriv required"; exit 2; }
id artest >/dev/null 2>&1 || useradd -r -s /usr/sbin/nologin artest
RUN_USER=artest

WPID=''
R=''
CH=''

new_case() {                    # $1=fake-serve mode  $2..=extra env for the wrapper
    local mode="${1:-normal}"; shift || true
    R="$(mktemp -d /tmp/arena-root.XXXXXX)"; chmod 0755 "$R"
    install -d -o root -g root -m 0755 "$R/$PIN_DIR"
    install -d -o "$RUN_USER" -g "$RUN_USER" -m 0700 "$R/home" "$R/tmp"
    # a supervisord-style world-readable log file the wrapper must tighten to 0640
    install -d -o "$RUN_USER" -g "$RUN_USER" -m 0750 "$R/log"
    : > "$R/log/arena.out.log"; chown root:root "$R/log/arena.out.log"; chmod 0644 "$R/log/arena.out.log"
    install -m 0555 "$FAKE" "$R/$PIN_DIR/ascii-royale"
    install -m 0444 "$HERE/../runtime/alsa-null.conf" "$R/alsa-null.conf"
    # seed the fake's scenario next to where the wrapper will put --ticket-file
    # (env -i means it can't be passed as an env var)
    install -d -o "$RUN_USER" -g "$RUN_USER" -m 0700 "$R/run/private"
    printf '%s' "$mode" > "$R/run/private/mode"; chmod 0644 "$R/run/private/mode"
    CH="$R/run/ascii-royale-m3/endpoint-id"
    # started in THIS shell (not a subshell) so `wait "$WPID"` really waits
    env ASCII_ROYALE_ARENA_ROOT="$R" \
        ASCII_ROYALE_ARENA_RUN_USER="$RUN_USER" \
        ASCII_ROYALE_ARENA_EXPECT_SHA256="$FAKE_SHA" \
        ASCII_ROYALE_ARENA_STARTUP_TIMEOUT=5 \
        ASCII_ROYALE_ARENA_HEARTBEAT_SECS=2 \
        "$@" \
        bash "$WRAPPER" >"$R/wrap.out" 2>"$R/wrap.err" &
    WPID=$!
}
stop_case() { kill -TERM "$WPID" 2>/dev/null; wait "$WPID" 2>/dev/null; }
drop_case() { rm -rf "$R"; }
wait_file() { local f="$1" n="${2:-60}"; while (( n-- )); do [[ -e "$f" ]] && return 0; sleep 0.2; done; return 1; }
field()     { sed -n "s/^$2=//p" "$1"; }
selflog()   { grep -h '^ascii-royale-arena:' "$R/wrap.out" "$R/wrap.err" 2>/dev/null; }

# =======================================================================
echo "[1] normal start: channel contract, privilege drop, secrecy, heartbeat"
new_case normal
if wait_file "$CH"; then
    chk "channel is a regular non-symlink file"     "[[ -f '$CH' && ! -L '$CH' ]]"
    chk "channel is root:root 0640"                 "[[ \$(stat -c '%u:%g:%a' '$CH') == 0:0:640 ]]"
    chk "channel dir is root:root 0750 non-symlink" "[[ \$(stat -c '%u:%g:%a' '$R/run/ascii-royale-m3') == 0:0:750 && ! -L '$R/run/ascii-royale-m3' ]]"
    chk "channel <= 1024 bytes"                     "[[ \$(stat -c %s '$CH') -le 1024 ]]"
    chk "version=1"                                 "[[ \$(field '$CH' version) == 1 ]]"
    chk "pinned_sha correct"                        "[[ \$(field '$CH' pinned_sha) == ac7d9771dfd788b278427db619e43989d4317029 ]]"
    chk "updated_unix is digits"                    "[[ \$(field '$CH' updated_unix) =~ ^[0-9]+\$ ]]"
    chk "host_generation shape ok"                  "[[ \$(field '$CH' host_generation) =~ ^[A-Za-z0-9._-]{1,64}\$ ]]"
    chk "endpoint_id is 64 hex"                     "[[ \$(field '$CH' endpoint_id) =~ ^[0-9a-f]{64}\$ ]]"
    chk "arena child ran unprivileged (uid != 0)"   "[[ \$(cat '$R/run/private/served.uid' 2>/dev/null) -gt 0 ]]"
    chk "wrapper process is root"                   "[[ \$(stat -c %u /proc/$WPID 2>/dev/null || echo 0) -eq 0 ]]"
    EID="$(field "$CH" endpoint_id)"
    chk "EndpointId not in the wrapper's own log lines" "! selflog | grep -q '$EID'"
    chk "arena log dir hardened to 0750"            "[[ \$(stat -c %a '$R/log') == 750 ]]"
    chk "arena.out.log tightened to 0640"           "[[ \$(stat -c %a '$R/log/arena.out.log') == 640 ]]"
    chk "arena.out.log group is the service account" "[[ \$(stat -c %G '$R/log/arena.out.log') == '$RUN_USER' ]]"

    u1="$(field "$CH" updated_unix)"; m1="$(stat -c %Y "$CH")"; sleep 5
    u2="$(field "$CH" updated_unix)"; m2="$(stat -c %Y "$CH")"
    chk "heartbeat: updated_unix advanced"          "[[ \$u2 -gt \$u1 ]]"
    chk "heartbeat: mtime advanced"                 "[[ \$m2 -gt \$m1 ]]"
    GEN1="$(field "$CH" host_generation)"
else
    no "channel never appeared in normal mode"; GEN1=""
fi

echo "[2] SIGTERM cleanup + restart rotates host_generation"
stop_case
chk "endpoint-id removed on TERM"                   "[[ ! -e '$CH' ]]"
chk "no .endpoint-id.* temp files remain"           "! compgen -G '$R/run/ascii-royale-m3/.endpoint-id.*' >/dev/null"
# restart against the SAME root
env ASCII_ROYALE_ARENA_ROOT="$R" ASCII_ROYALE_ARENA_RUN_USER="$RUN_USER" \
    ASCII_ROYALE_ARENA_EXPECT_SHA256="$FAKE_SHA" ASCII_ROYALE_ARENA_STARTUP_TIMEOUT=5 \
    ASCII_ROYALE_ARENA_HEARTBEAT_SECS=2 bash "$WRAPPER" >"$R/wrap2.out" 2>"$R/wrap2.err" &
WPID=$!
if wait_file "$CH"; then
    GEN2="$(field "$CH" host_generation)"
    chk "channel valid again after restart"         "[[ \$(field '$CH' endpoint_id) =~ ^[0-9a-f]{64}\$ ]]"
    chk "host_generation rotated"                   "[[ -n \"$GEN1\" && \"\$GEN2\" != \"$GEN1\" ]]"
else
    no "channel did not return after restart"
fi
stop_case; drop_case

echo "[3] fail-closed: no ticket -> startup timeout"
new_case no_ticket
seen=0
for _ in $(seq 1 60); do [[ -e "$CH" ]] && seen=1; kill -0 "$WPID" 2>/dev/null || break; sleep 0.25; done
wait "$WPID" 2>/dev/null; rc=$?
chk "no-ticket: wrapper exited non-zero"            "[[ $rc -ne 0 ]]"
chk "no-ticket: channel was never created"          "[[ $seen -eq 0 && ! -e '$CH' ]]"
chk "no-ticket: timeout reason logged"              "grep -q 'did not produce a valid ticket' '$R/wrap.err'"
drop_case

echo "[4] fail-closed: arena crash"
new_case crash
wait_file "$CH" 40 && ok "crash: channel published while arena was up" || no "crash: channel never published"
wait "$WPID" 2>/dev/null; rc=$?
chk "crash: wrapper exited non-zero"                "[[ $rc -ne 0 ]]"
chk "crash: channel removed after arena died"       "[[ ! -e '$CH' ]]"
drop_case

echo "[5] fail-closed: binary SHA-256 mismatch"
new_case normal ASCII_ROYALE_ARENA_EXPECT_SHA256=deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef
wait "$WPID" 2>/dev/null; rc=$?
chk "sha-mismatch: wrapper refused (non-zero)"      "[[ $rc -ne 0 ]]"
chk "sha-mismatch: channel never created"           "[[ ! -e '$CH' ]]"
chk "sha-mismatch: reason logged"                   "grep -qi 'SHA-256 mismatch' '$R/wrap.err'"
drop_case

echo
echo "==================  PASS=$PASS  FAIL=$FAIL  =================="
[[ $FAIL -eq 0 ]]
