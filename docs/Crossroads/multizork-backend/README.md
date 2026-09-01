# MultiZork backend (`multizorkd`) — source, patches & build provenance

The MultiZork Experience is backed by `multizorkd`, a small C daemon from the
MIT-licensed upstream project **`icculus/mojozork`**. It is *not* BinkTermPHP
code and the upstream repository is deliberately **not vendored** into this
tree — only our three local deltas, the build recipe, the provenance record
and a black-box regression harness live here.

Runtime deployment (supervisord, `/var/lib/multizork/…`, the manifest) is
documented separately in [`MultiZorkProduction.md`](../MultiZorkProduction.md).
This directory answers a narrower question: *how is the binary in
`/var/lib/multizork/bin/multizorkd` produced, and how do you reproduce it?*

**Status:** all three patches are **live in production as of 2026-09-01**
(deployed binary `multizorkd.p3`, `sha256 4f1d780c…88a9ef92`, build-id
`3ac91e07…`). The prior `0001`-only binary (`sha256 6dcdde18…5536fc89`) is
kept in-container as `multizorkd.6dcdde18.bak` for a binary-only rollback.

```
multizork-backend/
├── README.md                          ← this file
├── patches/
│   ├── 0001-bind-listener-to-loopback-only.patch
│   ├── 0002-redact-credential-bearing-input-lines.patch
│   └── 0003-redact-instance-join-codes.patch
├── build/
│   ├── reconstruct-source.sh           pin + patches  → *.c
│   ├── build-ubuntu2204.sh             *.c → multizorkd  (runs in a throwaway container)
│   └── verify-equivalence.sh           disassembly-level binary comparison
└── test/
    ├── run-regression.sh               full end-to-end, all in throwaway containers
    ├── harness.py                      the black-box scenario + assertions
    └── README.md
```

The full build/provenance artifacts for a given build (reconstructed `.c`
files, the built binaries, `build.env`, a snapshot of the previous production
binary, a `SHA256SUMS.txt`, and a `tar.gz` of the pinned upstream tree) are
kept **outside this repo** — a 100 KB binary that has a different SHA every
build does not belong in git. They live on the host at:

```
/root/multizork-build/<date>-<change>/
```

with the current one being `/root/multizork-build/2026-09-01-credential-redaction/`.

---

## Upstream pin

| | |
|---|---|
| Repository | `https://github.com/icculus/mojozork` |
| Pinned commit | `f94c3104aa18036d9ed5f0243814483f82e486cb` (2026-07-24, "ci: add Linux aarch64 libretro build") |
| `multizorkd.c` SHA-256 @ pin | `6bce2253e7f665baada5200b44a46cd322fc08bee39adbc30e319867fb22b1b0` |
| `mojozork.c` SHA-256 @ pin | `fdaff7424d3e35c12711ab87dfe6ed6a1b6f7c00e2d69790d0c737d3dc0db2a1` |
| Daemon version string | `multizork daemon 0.0.9` |

`multizorkd.c` `#include`s `mojozork.c` directly; both files are needed to
build, only `multizorkd.c` is patched.

The story artifact (`zork1-r88.dat`) is unchanged and its provenance is in
[`MultiZorkProduction.md`](../MultiZorkProduction.md)
(SHA-256 `158f1f63…80b1e7f9`).

---

## The three local patches

`0002` and `0003` are one logical hardening step ("never write a credential to
the daemon log") split for review; they could be squashed for an upstream PR.

### `0001` — bind the listener to loopback only

`prep_listen_socket()` calls `getaddrinfo(NULL, service, …)` with `AI_PASSIVE`,
which binds every interface. `multizorkd` has **no CLI flag** for a bind
address. The one-token change `NULL` → `"::1"` makes it accept connections
only from `::1` inside the container; the PHP line-relay (`MultiZorkAdapter`
via `scripts/line-relay-runtime.php`) is the only client and connects to
`[::1]:43023`.

This patch is **not new** — it is the single, already-disclosed modification
that has been in every deployed `multizorkd` since Slice 1. It was never kept
as a patch file; this is its reconstruction. It is verified byte-exact — see
*Reproducibility* below.

Network-binding only: no protocol, on-disk format, or gameplay change.

### `0002` — never log verbatim input for credential-bearing prompts

Upstream `process_connection_command()` logs **every** raw input line before
dispatch:

```c
loginfo("New input from socket %d%s: '%s'", conn->sock,
        conn->blocked ? " (blocked)" : "", conn->inputbuf);
```

Two input handlers receive a **secret** on that line:

| handler | secret on that line |
|---|---|
| `inpfn_hello_sailor` | a returning player's **access code** (`player->hash`) — the persistent credential `MultiZorkAdapter` stores and invisibly re-submits on every relaunch |
| `inpfn_enter_instance_code_to_join` | the **join code** (`inst->hash`) a second player types to enter a not-yet-started game |

The patch wraps that one `loginfo` call: when `conn->inputfn` is either of
those two handlers, it emits a fixed marker instead of the buffer —

```
multizorkd: New input from socket 7: <redacted>
```

— and logs **everything else exactly as before**. It is unconditional (no
config knob), generic (keyed on the upstream handler identity, no L33TEST
names, no MultiZork-specific string matching), and upstream-submittable as-is.
No protocol, schema, or gameplay change; the redacted `loginfo` even keeps the
`(blocked)` marker so malicious-bot diagnostics are unaffected.

The returning access code (`player->hash`) is never written anywhere else by
the daemon, so after this patch it never reaches the log at all. The join code
(`inst->hash`) is also logged by the instance-lifecycle statements — handled by
`0003`.

### `0003` — never log verbatim instance (join) codes

The per-game instance hash (`inst->hash` / `conn->instance->hash`) is the code
a second player types at `inpfn_enter_instance_code_to_join` to join a running
game — it authorises joining an expedition, so it is a credential. Eight
`loginfo(...)` lifecycle statements printed it verbatim:

| log line | site |
|---|---|
| `Created new instance '%s'` | `inpfn_new_game_or_join` |
| `Saving instance '%s'...` | `save_instance` |
| `Destroying instance '%s'` | `free_instance` |
| `Rehydrated archived instance '%s'` | `reconnect_player` |
| `Uhoh, instance '%s' has %d players in the database, should be %d!` | `db_select_instance` |
| `Player #%d on instance '%s' triggered the Zork 1 endgame!` | `step_instance` |
| `Um, socket %d is trying to talk to instance '%s', which it is not a player on.` | `inpfn_ingame` |
| `!! FATAL Z-MACHINE ERROR (instance='%s', err='%s', pc=%X, instructions_run=%u) !!` | `die_multizork` |

Each now logs the instance as `'<redacted>'` while keeping the lifecycle event
and every other field. The crash path is special: it builds the message once
and both **logs** it and **broadcasts** it to the players in that instance
(`broadcast_to_instance`, which also writes their transcript). Players still
see the full text with the hash — only the `loginfo` copy is redacted — so
gameplay/transcript output is byte-for-byte unchanged.

No protocol, schema, gameplay, or hash generation/validation change.

> **Still not a "log" but noted:** on a Z-machine crash the un-redacted
> message (with the hash) is written to the player's `transcripts` SQLite row
> and shown on their screen. That is gameplay-recap data, player-visible by
> design, and the transcript web view is itself addressed by the instance hash
> (`…/game/<hash>`). It is not the daemon log and is out of scope here.

---

## Building

The production runtime is the **Debian 13 (trixie)** `binkterm-app` container
(`libsqlite3.so.0` 3.46, glibc 2.41). The pre-2026-09-01 binary, however, was
built on an **Ubuntu 22.04** host with its system `gcc` — confirmed from its
`.comment` section (`GCC: (Ubuntu 11.4.0-1ubuntu1~22.04.3) 11.4.0`) and a
byte-exact rebuild. So that the *only* difference between the old and the
deployed binary is patches `0002`+`0003`, the build reproduces that toolchain
in a **disposable `ubuntu:22.04` container** — nothing installed on the host
or into `binkterm-app`.

| | |
|---|---|
| Build environment | `docker run --rm ubuntu:22.04` (throwaway) |
| Compiler | `gcc (Ubuntu 11.4.0-1ubuntu1~22.04.3) 11.4.0` |
| Linker | `GNU ld (GNU Binutils for Ubuntu) 2.38` |
| Headers/libs | `libc6-dev 2.35-0ubuntu3.14`, `libsqlite3-dev 3.37.2-2ubuntu0.7` (Ubuntu 22.04) |
| Build command | `gcc -O2 -DNDEBUG -Wall -o multizorkd multizorkd.c -lsqlite3` |

> The `-DNDEBUG` was **missing from the description in
> [`MultiZorkProduction.md`](../MultiZorkProduction.md)** (which said
> `gcc -O2 -Wall`). The pre-2026-09-01 binary is demonstrably built *with*
> `-DNDEBUG` — 99 312 bytes, 17 530-byte `.rodata`, no `assert()` strings;
> a `-O2 -Wall` build without `-DNDEBUG` is ~104 KB with ~18.6 KB `.rodata`.
> The production doc has been corrected.

```sh
# one command, fully containerised, host untouched:
cd docs/Crossroads/multizork-backend
build/reconstruct-source.sh /tmp/mzsrc
cp build/build-ubuntu2204.sh /tmp/mzsrc/
docker run --rm -v /tmp/mzsrc:/src ubuntu:22.04 bash /src/build-ubuntu2204.sh
# → /tmp/mzsrc/out/multizorkd.p3   (== the deployed production binary)
#   /tmp/mzsrc/out/multizorkd.p2   (input redaction only, intermediate)
#   /tmp/mzsrc/out/multizorkd.p1   (loopback-only, for the equivalence check)
#   /tmp/mzsrc/out/build.env       (recorded toolchain + hashes)
```

---

## Reproducibility

`multizorkd.c` embeds `__DATE__ " " __TIME__` in two strings, so **no two
builds share a SHA-256** and there is no point pinning one. Reproducibility is
instead asserted at the instruction level with `build/verify-equivalence.sh`
(normalises addresses / symbol offsets / NOP padding out of `objdump -d`,
then diffs):

| comparison | result | meaning |
|---|---|---|
| pre-2026-09-01 production `multizorkd` (`6dcdde18…`) **vs** freshly-built `multizorkd.p1` | **0 differing instructions, 0 functions changed** | patch `0001` is reconstructed exactly and the `ubuntu:22.04` build environment faithfully reproduces the production toolchain |
| `multizorkd.p1` **vs** `multizorkd.p2` | one function (`main`; `process_connection_command` is `static` and `-O2`-inlined into it): the `conn->inputfn` compare + one extra `loginfo("… <redacted>")` | patch `0002` minimal and contained |
| `multizorkd.p2` **vs** `multizorkd.p3` | **7 functions** — exactly the seven holding one of the eight redacted `loginfo` sites (`db_select_instance` + `reconnect_player` both inline into `inpfn_hello_sailor`). rodata delta: the 7 `instance '%s'` format strings replaced by `'<redacted>'` variants, plus one added redacted FATAL string; the original FATAL string is **retained** (still used for the player broadcast). Nothing else. | patch `0003` touches only the intended log statements |

Binary identities for the 2026-09-01 build (informational — expect different
SHAs on rebuild, identical disassembly):

| artifact | SHA-256 | GNU build-id |
|---|---|---|
| pre-2026-09-01 production `multizorkd` (now `multizorkd.6dcdde18.bak`) | `6dcdde18558885d8de2fdaebba6a7fb194072a2e9713a353e02b47525536fc89` | `0e0957ae4dc205517fdb26a68ec4449071fd37f2` |
| `multizorkd.p1` (loopback only, rebuild) | `fb6fa96125e1f1bb6a6a5faab510eeebdc1fae37396068b0941653ed6cf538ca` | `2ec183e90d1290a3ffbc638040d02bfcb073c25c` |
| `multizorkd.p2` (+ input redaction) | `9cf0a240133ba052f3e12cd6ab6a027e3234ad17dfa698824a843dbac83b7ad9` | `da8a3a880639788d8397742ae8114412c496a7e1` |
| **`multizorkd.p3` — DEPLOYED to production 2026-09-01** | `4f1d780cf0ea98061ceaebb5ae4321907edb4eede7f3d3cec84530ba88a9ef92` | `3ac91e0762cb37215e6dbc9292407192f5217d2b` |
| `multizorkd.p3.c` (source) | `bd09f307f2766b607ac7ceaa35974547fca7be761bf4756600a5548c38d09137` | — |

(The install SHA-256 is per-build — the disassembly equivalence above is the
reproducibility guarantee. Out-of-repo build/provenance archive:
`/root/multizork-build/2026-09-01-credential-redaction/`.)

---

## Regression harness

`test/run-regression.sh` — reconstructs, builds and tests entirely in
throwaway containers; the production container / DB / story are never touched
(the story file is only *read*, and hash-checked). See
[`test/README.md`](test/README.md). Latest run: **12/12** redaction
assertions (against `p3`), **5/5** control assertions (against `p1`),
transcript differential clean.

The `redacted`-mode assertions include: *no* credential value (returning
access code, invalid code, or **join code**) appears **anywhere** in the
captured daemon log; the instance-lifecycle events are still logged and now
carry the `<redacted>` marker; ordinary gameplay input and non-secret log
fields (sockets, player counts, story name, connection origin) are unchanged.

---

## Credential-log exposure status

| credential | logged verbatim before | after `0002`+`0003` |
|---|---|---|
| returning player access code (`player->hash`) | yes — raw input line | **never logged** |
| expedition join code (`inst->hash`) | yes — raw input line **and** 8 lifecycle `loginfo` lines | **never logged** (lifecycle lines say `'<redacted>'`) |
| invalid code typed at a prompt | yes — raw input line | **never logged** |

Not a log, deliberately unchanged: the join code appears in the transcript
web URL (`…/game/<hash>`) and, on a Z-machine crash only, in the player-facing
crash message + that player's transcript row — all gameplay-recap surfaces
addressed by the hash by design.

Not remediated: credential-shaped tokens in *historical* log lines written
*before* the 2026-09-01 deploy (`multizorkd.out.log`, and the pre-Slice-3
shared `docker logs binkterm-app` JSON stream). Left in place, not truncated.

**Deployed to production 2026-09-01.** Live-verified via a Crossroads smoke:
real caller launch, returning-access-code auto-submission, ordinary gameplay,
and the restricted daemon log showing `<redacted>` for every credential-bearing
input and instance-lifecycle line — no credential value present anywhere in
the post-deploy log. Rollback binary `multizorkd.6dcdde18.bak` retained
in-container (temporary).
