# ascii-royale backend — pinned upstream, build provenance & runtime package

The ascii-royale Experience (Crossroads Experience #2) is backed by
**`ascii-royale`**, a terminal battle-royale client/arena from the upstream
project **`chad/ascii-royale`** (peer-to-peer over `iroh`). It is *not*
BinkTermPHP code and the upstream repository is deliberately **not vendored**
into this tree.

**There are no local source patches.** M3 proved the unmodified upstream
binary integrates cleanly, and M4 keeps it unmodified: the production arena
runs the pinned `serve` subcommand, audio is silenced with an external ALSA
config, and the endpoint hand-off is done entirely outside the binary by a
small privileged wrapper. Only that wrapper, the build/verify recipe, this
provenance record and a black-box regression harness live here.

Runtime deployment (supervisord, `/var/lib/ascii-royale/…`, the bridge
environment) is documented in
[`AsciiRoyaleProduction.md`](../AsciiRoyaleProduction.md). The committed door
manifest + launcher + tests are the M3 five-file package under
`native-doors/doors/ascii-royale-m3/` and `tests/Unit/AsciiRoyaleM3*` —
**unchanged by M4**.

```
ascii-royale-backend/
├── README.md                              ← this file
├── build/
│   ├── build-arena.sh                       pinned upstream → release binary (disposable container)
│   └── verify-binary.sh                     assert a binary is the approved SHA-256
├── runtime/
│   ├── ascii-royale-arena.sh               REFERENCE COPY of the privileged lifecycle wrapper
│   ├── supervisord.ascii-royale.conf.fragment   the [program:ascii-royale-arena] block
│   └── alsa-null.conf                       external ALSA null device (no upstream audio patch)
└── test/
    ├── run-regression.sh                    entrypoint: drives the wrapper in a throwaway container
    ├── wrapper-contract.sh                  the black-box assertions
    ├── fake-serve.sh                        test double for `ascii-royale serve`
    └── README.md
```

---

## Upstream pin

| | |
|---|---|
| Repository | `https://github.com/chad/ascii-royale` |
| Pinned commit | `ac7d9771dfd788b278427db619e43989d4317029` |
| Transport | `iroh` (QUIC p2p; n0 relay + discovery for reachability) |
| Production candidate binary SHA-256 | `b7d59c4083e4b2ef3664be57145a70bfbb178db170efbb989e2580fe56d8d84e` |
| Local patches | **none** |

The retained production-candidate binary is at
`data/runtime/ascii-royale-m3/ac7d9771dfd788b278427db619e43989d4317029/ascii-royale`
(git-ignored runtime artifact, never committed) and is the exact binary M3
acceptance ran against. The out-of-repo build tree used to produce it is on
the host at `/tmp/ascii-royale-m3-build-ac7d9771.CLssl6/`.

---

## Building & verifying

```sh
cd docs/Crossroads/ascii-royale-backend
build/build-arena.sh /tmp/arena-build          # clone pin + `cargo build --release --locked` in a throwaway container
build/verify-binary.sh /tmp/arena-build/ascii-royale
```

Rust release builds are **not guaranteed bit-reproducible** across toolchain
or host differences (unlike the C `multizorkd` build). `verify-binary.sh` is
therefore the gate that matters: a deployed binary MUST hash to
`b7d59c40…d8d84e`. `build-arena.sh` records its exact toolchain in
`build.env`; if a rebuild's hash differs, pin `BUILD_IMAGE` to that toolchain
and escalate before deploying anything whose SHA-256 is not the approved one.

---

## Why `serve` and not `host`

| | `host` (M3 proof) | `serve` (M4 production) |
|---|---|---|
| local player / TUI | yes — needs a PTY | **no** — headless |
| match start | operator presses a key (M3's `OpenStdin` problem) | **automatic** once a human is in the lobby (`--auto-start-secs`) |
| lobby reset | operator returns from results | **automatic** (`--auto-reset-secs`) |
| ticket hand-off | scrape stdout | **`--ticket-file`** (upstream-supported) |

`serve` removes the interactive-control requirement entirely. Production
policy for this slice:

```
ascii-royale serve --bots 9 --auto-start-secs 20 --auto-reset-secs 12 \
                   --ticket-file /var/lib/ascii-royale/run/private/ticket.raw
```

`--http-port` is **not** passed (no listener, no published port).
`--stats-file` / `--browser-play-url` / `--announce` flags are not used — but
note `serve` sets `announce: true` internally with `bootstrap: None`
(see [`AsciiRoyaleProduction.md`](../AsciiRoyaleProduction.md#announce)).

### Bot count: 9

`ascii-royale` seats **16** players per match
(`GameConfig::default().max_players`). The M3 door manifest caps concurrent
BinkTerm callers at `max_nodes: 4`, and this slice does **not** open the
Experience to ordinary users, so realistic near-term load is 1–4 human
callers. `--bots 9` means:

- a lone caller still drops into a 10-combatant match (never a ghost town);
- all 4 callers + 9 bots = 13 ≤ 16, so a full house never rejects a caller;
- 9 < 16 leaves headroom and does not drown out human-vs-human play.

It is also the exact value M3 acceptance ran with, so match feel is unchanged.

---

## The privileged wrapper

`runtime/ascii-royale-arena.sh` is the only component that runs as root, and
only because the committed M3 launcher will trust an endpoint record **only**
if it is a `root:root 0640` file in a `root:root 0750` directory. The wrapper:

1. verifies the deployed binary's SHA-256 against the pin before launch;
2. refuses to start on any unsafe ownership / mode / symlink / missing path;
3. launches `ascii-royale serve` as the unprivileged **`ascii-royale`**
   account via `setpriv … --no-new-privs -- env -i …` (pristine environment;
   the arena never runs as root);
4. waits for `--ticket-file` to hold a `^[0-9a-f]{64}$` value, failing closed
   (non-zero exit → supervisor restart) on a startup timeout or an early
   child exit — **and never publishes a channel before a valid ticket**;
5. publishes the 5-field record atomically (`mktemp` in the channel dir →
   `chmod 0640` → `chown 0:0` → `mv`), refreshing it every ~5 s with a fresh
   `updated_unix`;
6. picks a new `host_generation` on every start, so a record from a previous
   arena is rejected by the launcher even inside the 15 s freshness window;
7. on `TERM`/`INT`/`EXIT`: stops the exact child, then removes **both**
   `endpoint-id` **and** every `.endpoint-id.*` temp file — the one hygiene
   defect in the disposable M3 `heartbeat.sh` (its trap removed only the temp
   files) is fixed here;
8. never prints or logs the EndpointId. Only upstream `serve` prints it
   (`[arena] ticket: <id>` on stdout) — which is why the supervisor program's
   logs go to the private `/var/lib/ascii-royale/log/` and **not** to the
   shared `docker logs` stream. Because supervisord creates/rotates those
   files with a `0644` umask, the wrapper re-applies `0640` `root:ascii-royale`
   to `log/arena.*.log` in preflight and on every heartbeat (dir stays `0750`)
   — private at both levels, self-healing across a mid-run rotation.

The wrapper takes **no** positional arguments. Production passes **no**
environment overrides; the `ASCII_ROYALE_ARENA_*` overrides exist only for
`test/` and are each strictly validated (absolute existing non-symlink dir,
64-hex digest, existing non-root account, small integers) — the same
`${VAR:-default}`-then-validate style the committed M3 launcher itself uses
for `ASCII_ROYALE_M3_*`.

---

## Regression harness

`test/run-regression.sh` drives the **exact committed wrapper** against
`test/fake-serve.sh` (a `serve` stand-in) inside a disposable
`binktermphp-binkterm-app` container — no network, no real binary, the
production `/var/lib/ascii-royale` and running arena untouched. See
[`test/README.md`](test/README.md) for the assertion list.
