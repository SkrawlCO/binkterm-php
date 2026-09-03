#!/usr/bin/env bash
#
# Recreate/restart-survival proof for the durable OpenGlad relay.
#
#   ./recreate-survival.sh
#
# Builds an overlay image that bakes [program:openglad-relay] + the
# /openglad-relay Caddy route into binktermphp-binkterm-app:latest (simulating
# the L33TEST image build), then:
#   1. runs a container, waits for it to be up, checks the relay + route
#   2. `docker restart`  -> re-checks (survives a restart)
#   3. `docker rm -f` + fresh `docker run` -> re-checks (survives a RECREATE
#      with ZERO hand steps -- the Slice 1F durability property)
#   4. also runs the hermetic contract regression inside the recreated container
#
# Uses a throwaway container name + no host DB. Nothing on the live stack is
# touched. ~3 min; needs docker.
#
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../../../.." && pwd)"
BASE="${BASE_IMAGE:-binktermphp-binkterm-app:latest}"
IMG="openglad-relay-survival:test"
NAME="og-relay-survival-$$"

cleanup() {
  docker rm -f "$NAME" >/dev/null 2>&1 || true
  docker image rm "$IMG" >/dev/null 2>&1 || true
}
trap cleanup EXIT

pass=0
fail=0
say() { printf '  [%s] %s\n' "$1" "$2"; }
ok() { pass=$((pass + 1)); say PASS "$1"; }
no() { fail=$((fail + 1)); say FAIL "$1"; }

check_up() {
  local label="$1" tries=60
  # supervisor: relay RUNNING
  local st=""
  for _ in $(seq 1 "$tries"); do
    st="$(docker exec "$NAME" supervisorctl status openglad-relay 2>/dev/null || true)"
    [[ "$st" == *RUNNING* ]] && break
    sleep 1
  done
  [[ "$st" == *RUNNING* ]] && ok "$label: supervisor openglad-relay RUNNING" || no "$label: supervisor status = ${st:-<none>}"

  # route: GET /openglad-relay/healthz -> 200 ok  (through the baked Caddy route)
  local body=""
  for _ in $(seq 1 "$tries"); do
    body="$(docker exec "$NAME" sh -c 'curl -s -m2 http://127.0.0.1/openglad-relay/healthz' 2>/dev/null || true)"
    [[ "$body" == "ok" ]] && break
    sleep 1
  done
  [[ "$body" == "ok" ]] && ok "$label: GET /openglad-relay/healthz -> ok (baked Caddy route)" || no "$label: healthz body = '${body}'"

  # loopback-only: the relay's own startup line records the bind host, and
  # /proc/net/tcp shows the listener address (0100007F = 127.0.0.1).
  local logline hexlisten
  logline="$(docker exec "$NAME" sh -c 'grep -h "listening" /var/www/html/data/logs/openglad_relay.log 2>/dev/null | tail -1')"
  hexlisten="$(docker exec "$NAME" sh -c 'cat /proc/net/tcp 2>/dev/null | awk "\$2 ~ /:179B$/ {print \$2}"')" # 0x179B = 6035
  if [[ "$logline" == *"host=127.0.0.1"* && ( -z "$hexlisten" || "$hexlisten" == 0100007F:* ) ]]; then
    ok "$label: relay bound 127.0.0.1:6035 only (log: host=127.0.0.1; /proc/net/tcp: ${hexlisten:-n/a})"
  else
    no "$label: bind check — log='${logline}' proc='${hexlisten}'"
  fi
}

echo "== OpenGlad relay recreate/restart-survival (base: $BASE) =="

echo "-- build overlay image (bakes supervisord block + Caddy route) --"
docker build -q -f "$HERE/Dockerfile.survival" --build-arg "BASE=$BASE" -t "$IMG" "$REPO" >/dev/null
ok "overlay image builds (caddy validate passed in the build)"

run_container() {
  docker rm -f "$NAME" >/dev/null 2>&1 || true
  docker run -d --name "$NAME" --network none "$IMG" >/dev/null
}

echo "-- 1. first start --"
run_container
check_up "start"

echo "-- 2. docker restart --"
docker restart "$NAME" >/dev/null
check_up "after restart"

echo "-- 3. docker rm -f + fresh run (RECREATE) --"
run_container
check_up "after recreate"

echo "-- 4. hermetic contract regression inside the recreated container --"
docker cp "$HERE/relay-contract.mjs" "$NAME:/tmp/relay-contract.mjs" >/dev/null
reg="$(docker exec "$NAME" node /tmp/relay-contract.mjs /var/www/html/scripts/openglad/openglad-relay-runtime.cjs 2>&1 || true)"
last="$(printf '%s\n' "$reg" | tail -1)"
if printf '%s\n' "$reg" | grep -q '\[FAIL\]'; then
  no "in-container contract regression: $(printf '%s\n' "$reg" | grep '\[FAIL\]' | tr '\n' ' ')"
elif [[ "$last" == *" PASS" ]]; then
  ok "in-container contract regression: $last"
else
  no "in-container contract regression: $last"
fi

echo
echo "$pass/$((pass + fail)) PASS"
[[ "$fail" -eq 0 ]]
