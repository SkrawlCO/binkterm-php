# ascii-royale production deployment (Crossroads Experience #2, M4 Slice 1)

This document covers the durable production runtime for the ascii-royale
Experience: the managed shared arena, its runtime artifacts, and how another
SysOp reproduces the deployment. The committed M3 integration — the door
manifest, the launcher and its unit tests under
`native-doors/doors/ascii-royale-m3/` and `tests/Unit/AsciiRoyaleM3*` — is
**unchanged by M4**; this slice only adds a durable way to run the arena the
launcher already knows how to talk to.

The upstream pin, the (absence of) source patches, the build recipe and the
wrapper regression harness are in
[`ascii-royale-backend/`](ascii-royale-backend/README.md).

> **Scope of M4 Slice 1.** This slice makes the arena a durable,
> supervisor-managed service and proves the committed launcher accepts its
> record. It does **not** enable the Experience for ordinary users and does
> **not** address Telnet — those are later M4 slices. `config/nativedoors.json`
> keeps `ascii-royale-m3.enabled = false`.

---

## Service lifecycle

`binkterm-app`'s container already uses `supervisord`
(`/etc/supervisor/conf.d/supervisord.conf`) to run every long-lived companion
process (`caddy`, `php-fpm`, `binkp_server`, `dosdoor_bridge`, `telnet`,
`multizorkd`, …) with `autostart=true`/`autorestart=true`. The ascii-royale
arena is registered the same way, as **`[program:ascii-royale-arena]`**, so it
starts with the container and is restarted automatically if it ever exits — no
new infrastructure mechanism.

This mirrors the existing `[program:multizorkd]` precedent for a persistent
game backend, and the `dosdoor_bridge`/Elsewhere env-wiring precedent: the
block is a **live edit to the running container's `supervisord.conf`, not
baked into the Docker image**. It survives an ordinary `docker restart
binkterm-app` (that file is on the container's writable layer, not the
bind-mounted app directory). It does **not** survive the container being
recreated (`docker compose up --force-recreate`, an image rebuild) without
re-applying the block and re-staging `/var/lib/ascii-royale/` — a deliberate,
disclosed limitation matching the `multizorkd`/Elsewhere precedent.

Operational commands (inside the `binkterm-app` container):

```
supervisorctl status ascii-royale-arena     # check state
supervisorctl restart ascii-royale-arena    # restart just the arena (rotates the EndpointId)
supervisorctl tail ascii-royale-arena       # recent wrapper stdout (see the log note below)
```

### Why the arena needs a wrapper, and why it runs as root

The committed launcher (`native-doors/doors/ascii-royale-m3/
launch-ascii-royale.sh`) will only trust an endpoint record that is a
`root:root 0640` regular file inside a `root:root 0750` directory, no older
than 15 s, carrying `version=1`, the pinned SHA, a `host_generation` and a
64-hex `endpoint_id`. Upstream `ascii-royale serve` neither writes that record
nor runs as root. So `[program:ascii-royale-arena]` runs a small privileged
wrapper —
[`ascii-royale-backend/runtime/ascii-royale-arena.sh`](ascii-royale-backend/runtime/ascii-royale-arena.sh)
— that does exactly four privileged things:

1. maintains the `root:root` endpoint channel (atomic publish, ~5 s heartbeat);
2. launches `ascii-royale serve` as the unprivileged **`ascii-royale`**
   account (`setpriv --clear-groups --no-new-privs -- env -i …`); the arena
   itself never runs as root;
3. supervises that one child by exact PID;
4. removes the channel — **and every `.endpoint-id.*` temp file** — on
   `TERM`/`INT`/`EXIT`.

It verifies the deployed binary's SHA-256 against the pin before every launch,
refuses to start on any unsafe path/mode/ownership, never publishes a channel
before a valid ticket exists, picks a fresh `host_generation` each start, and
fails closed (non-zero exit → supervisor restart) on a startup timeout or an
early arena exit. It never prints or logs the EndpointId. See
[`ascii-royale-backend/README.md`](ascii-royale-backend/README.md#the-privileged-wrapper).

### Log privacy note (upstream prints the EndpointId)

Upstream `serve` prints `[arena] ticket: <EndpointId>` and `[arena] join
with: ascii-royale join <EndpointId>` (and `[lobby] announcing on gossip;
bootstrap id <id>`) to **stdout**. That is not patched out. `arena.out.log`
therefore **contains the EndpointId** and must be treated as private runtime
state — same posture as `multizorkd`'s log.

The `[program:ascii-royale-arena]` block points `stdout_logfile` /
`stderr_logfile` at **`/var/lib/ascii-royale/log/`**, *not* `/dev/stdout`, so
the EndpointId never enters the shared `docker logs binkterm-app` stream.
The contract is private at **both** levels:

| | owner:group | mode |
|---|---|---|
| `/var/lib/ascii-royale/log/` | `ascii-royale:ascii-royale` | `0750` |
| `arena.out.log`, `arena.err.log` (+ rotations) | `root:ascii-royale` | `0640` |

supervisord creates and rotates those files with its own umask (`0644`), so
the wrapper (`ascii-royale-arena.sh`) re-applies `0640` `root:ascii-royale` in
preflight **and on every heartbeat** — a rotated `0644` file is re-tightened
within `HEARTBEAT_SECS` (~5 s). `docs`/tests never carry a real EndpointId.

---

## Durable artifact locations

Everything lives under **`/var/lib/ascii-royale/`** inside the container —
outside `/var/www/html` (the bind-mounted git repo), so none of it can be
confused with or committed to tracked source. A dedicated, unprivileged
service account (`ascii-royale`, not `www-data`) owns the arena's own state
and runs `serve`.

| Path | Contents | Ownership / mode |
|---|---|---|
| `/var/lib/ascii-royale/` | service root (must be traversable by the account) | `root:root` `0755` |
| `/var/lib/ascii-royale/bin/ascii-royale-arena.sh` | the privileged wrapper | `root:root` `0755` |
| `/var/lib/ascii-royale/<PIN>/ascii-royale` | the pinned arena binary (path shape required by the launcher: `$runtime_root/$PINNED_SHA/ascii-royale`) | `root:root` `0555` |
| `/var/lib/ascii-royale/alsa-null.conf` | external ALSA null device | `root:root` `0444` |
| `/var/lib/ascii-royale/home/` | arena `HOME` | `ascii-royale:ascii-royale` `0700` |
| `/var/lib/ascii-royale/tmp/` | arena `TMPDIR` | `ascii-royale:ascii-royale` `0700` |
| `/var/lib/ascii-royale/run/` | runtime dir | `root:root` `0755` |
| `/var/lib/ascii-royale/run/private/ticket.raw` | raw 64-hex ticket from `serve --ticket-file` — **private runtime state (the EndpointId)** | `ascii-royale:ascii-royale`, dir `0700` |
| `/var/lib/ascii-royale/run/ascii-royale-m3/endpoint-id` | the record the launcher reads (`ASCII_ROYALE_M3_CHANNEL`) | `root:root` `0640`, dir `root:root` `0750` |
| `/var/lib/ascii-royale/log/` | wrapper + `serve` output dir | `ascii-royale:ascii-royale` `0750` |
| `/var/lib/ascii-royale/log/arena.{out,err}.log` (+ rotations) | wrapper + `serve` output — **contains the EndpointId** (upstream prints it); wrapper enforces the mode each heartbeat | `root:ascii-royale` `0640` |

`<PIN>` = `ac7d9771dfd788b278427db619e43989d4317029`.

The binary is SHA-path-addressed, so an upgrade drops a new
`/var/lib/ascii-royale/<newsha>/ascii-royale` beside the old one and both the
wrapper and the launcher switch by changing the single pinned-SHA constant —
the previous tree stays in place for rollback (see *Upgrade / rollback*).

---

## Launcher wiring (no launcher change)

The committed launcher already supports two environment overrides
(`${VAR:-default}`-then-validate). M4 sets them on the **existing**
`[program:dosdoor_bridge]` `environment=` line — the same mechanism that
already carries `WORLD_GATEWAY_*` / `ELSEWHERE_*` — appended, disturbing
nothing else:

```
ASCII_ROYALE_M3_RUNTIME_ROOT="/var/lib/ascii-royale"
ASCII_ROYALE_M3_CHANNEL="/var/lib/ascii-royale/run/ascii-royale-m3/endpoint-id"
```

With these, the launcher resolves:

| launcher variable | value |
|---|---|
| `runtime_root` | `/var/lib/ascii-royale` |
| `binary` | `/var/lib/ascii-royale/<PIN>/ascii-royale` |
| `alsa_config` | `/var/lib/ascii-royale/alsa-null.conf` |
| `channel` | `/var/lib/ascii-royale/run/ascii-royale-m3/endpoint-id` |

The launcher runs as a child of `dosdoor_bridge`, which runs as **root**, so
it can read the `root:root 0640` channel. (If a future change sets
`user=` on `[program:dosdoor_bridge]`, the launcher can no longer read the
channel and every ascii-royale launch fails closed — call this out before
making such a change.)

<a name="announce"></a>

## `announce` behaviour (accepted, not patched)

`serve` sets `announce: true` internally with `bootstrap: None` and no HTTP
registration. Recon and the locked M4 design established this is acceptable:
the arena never dials the public boxd hub and is not discoverable via
`browse` / `play`; announcement failure is non-fatal (`serve` logs
`[lobby] announce disabled: …` and continues). The only residual cost is a
second bound `iroh` gossip endpoint broadcasting into an unbootstrapped
topic. **This is not patched.** Revisit only if live production evidence shows
a real problem.

`iroh` reachability (`endpoint.online()`, the "waiting to be reachable" phase)
needs outbound access to n0's relay/discovery infrastructure. If the container
loses internet egress, the arena will not produce a ticket; the wrapper then
fails closed after `STARTUP_TIMEOUT` (60 s) and supervisor keeps retrying.

---

## Deploying (Phase B — controlled live provisioning)

Run inside the **current** `binkterm-app` container. This does not rebuild the
image or recreate the container.

```sh
# 1. service account (dedicated, unprivileged, nologin, matching group)
useradd --system --user-group --home-dir /var/lib/ascii-royale \
        --shell /usr/sbin/nologin ascii-royale

# 2. hierarchy
install -d -o root -g root          -m 0755 /var/lib/ascii-royale /var/lib/ascii-royale/bin \
                                            /var/lib/ascii-royale/run
install -d -o ascii-royale -g ascii-royale -m 0700 /var/lib/ascii-royale/home \
                                            /var/lib/ascii-royale/tmp
install -d -o ascii-royale -g ascii-royale -m 0750 /var/lib/ascii-royale/log
install -d -o root -g root          -m 0750 /var/lib/ascii-royale/run/ascii-royale-m3
install -d -o ascii-royale -g ascii-royale -m 0700 /var/lib/ascii-royale/run/private
install -d -o root -g root          -m 0755 /var/lib/ascii-royale/ac7d9771dfd788b278427db619e43989d4317029

# 3. pinned binary (from the retained M3 artifact) + verify
install -m 0555 -o root -g root \
  /var/www/html/data/runtime/ascii-royale-m3/ac7d9771dfd788b278427db619e43989d4317029/ascii-royale \
  /var/lib/ascii-royale/ac7d9771dfd788b278427db619e43989d4317029/ascii-royale
sha256sum /var/lib/ascii-royale/ac7d9771dfd788b278427db619e43989d4317029/ascii-royale
# must equal b7d59c4083e4b2ef3664be57145a70bfbb178db170efbb989e2580fe56d8d84e

# 4. ALSA config + wrapper (from the tracked reference copies)
install -m 0444 -o root -g root \
  /var/www/html/docs/Crossroads/ascii-royale-backend/runtime/alsa-null.conf \
  /var/lib/ascii-royale/alsa-null.conf
install -m 0755 -o root -g root \
  /var/www/html/docs/Crossroads/ascii-royale-backend/runtime/ascii-royale-arena.sh \
  /var/lib/ascii-royale/bin/ascii-royale-arena.sh

# 5. supervisor program — BACK UP THE CONFIG FIRST
cp -a /etc/supervisor/conf.d/supervisord.conf \
      /etc/supervisor/conf.d/supervisord.conf.bak.<UTC-timestamp>
#   append docs/Crossroads/ascii-royale-backend/runtime/supervisord.ascii-royale.conf.fragment
#   append the two ASCII_ROYALE_M3_* assignments to the dosdoor_bridge `environment=` line
supervisorctl reread
supervisorctl add ascii-royale-arena
#   dosdoor_bridge only picks up the new env on restart; do that ONLY with zero
#   open door sessions, and restart ONLY that program:
#   supervisorctl restart dosdoor_bridge
```

Never restart the whole container or unrelated supervisor programs for this.

---

## Validation (record the results in the slice report)

1. `supervisorctl status ascii-royale-arena` → `RUNNING`.
2. the actual `ascii-royale serve` process runs as `ascii-royale` (uid ≠ 0);
   the wrapper is the only root process in the tree.
3. deployed binary SHA-256 == `b7d59c40…d8d84e`
   (`ascii-royale-backend/build/verify-binary.sh`).
4. `/var/lib/ascii-royale/run/ascii-royale-m3/endpoint-id`: regular
   non-symlink, `root:root 0640`, dir `root:root 0750`, ≤ 1024 bytes,
   `version=1` / correct `pinned_sha` / `host_generation` shaped /
   `endpoint_id` 64-hex, `updated_unix` fresh. **Do not print the EndpointId.**
5. the committed launcher, pointed at the production channel through the
   bridge environment, accepts the record with no launcher change (validate
   the record contract; a full human game session is not required).
6. arena logs are under `/var/lib/ascii-royale/log/` (dir `0750`
   `ascii-royale:ascii-royale`; `arena.{out,err}.log` `0640`
   `root:ascii-royale`) and absent from `docker logs binkterm-app`.
7. `supervisorctl restart ascii-royale-arena` → a **new** `host_generation`,
   a fresh valid channel, arena healthy.
8. `ascii-royale-backend/test/run-regression.sh` → all assertions pass
   (startup-timeout + crash + `TERM` cleanup incl. temp files).
9. unrelated services (`multizorkd`, `dosdoor_bridge`, `caddy`, …) healthy.

---

## Upgrade / rollback

- **Upgrade the binary:** build + verify a new binary
  (`ascii-royale-backend/build/`), `install -m 0555` it at
  `/var/lib/ascii-royale/<newsha>/ascii-royale`, update the pinned-SHA
  constant in the deployed wrapper and the `PINNED_SHA` in the committed
  launcher (a tracked change — its own review), then
  `supervisorctl restart ascii-royale-arena`. Keep the old
  `/var/lib/ascii-royale/<oldsha>/` tree in place until the new binary soaks.
- **Rollback:** revert the two SHA constants and restart the program; the old
  SHA-addressed tree is still there.
- **Config:** the `supervisord.conf` block and the two bridge env assignments
  are the entire deployment surface; the `.bak.<timestamp>` copy is the
  restore point.

---

## Deferred / disclosed limitations (as of this slice)

- `/var/lib/ascii-royale` and the `supervisord.conf` edit live on the
  `binkterm-app` container writable layer. **Recreating that container loses
  the arena deployment** (not the durable player state — the arena keeps
  none — but the binary, wrapper and config edit). Same limitation as
  `multizorkd`.
- `serve`'s `announce: true` is accepted, not patched (see
  [above](#announce)).
- The EndpointId rotates on every arena restart (`serve` binds a fresh
  endpoint; no secret-key persistence). New launches always read the fresh
  record; a caller mid-`join` during a restart must relaunch. Persistent
  identity would need upstream support.
- `/var/lib/ascii-royale/log/arena.out.log` contains the EndpointId (upstream
  prints it). Private at both levels (dir `0750`, files `0640`
  `root:ascii-royale`, wrapper-enforced each heartbeat), not redacted.
- Ordinary-user enablement and Telnet are **out of scope for this slice**.
