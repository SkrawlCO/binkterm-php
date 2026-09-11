# Tatham Light Up backend

M4 uses upstream revision `428913c6a58b60802fe5d734d9a44c34159f4e0f`
(release `20260911.428913c`) and puzzle `7x7:cBd0c1hBe2h1c0d0c` only.
`upstream.lock.json` locks the source archive and published Web artifacts.
Upstream source is extracted into temporary build directories and is not modified.

## Prepare artifacts

From the repository root, supply the verified release directory containing
`published-source.tar.gz` and `lightup.html`, `lightup.js`, `lightup.wasm`:

```sh
bash docs/Crossroads/tatham-backend/build.sh /path/to/release --terminal
bash docs/Crossroads/tatham-backend/prepare-validator.sh /path/to/release/published-source.tar.gz
```

The optional `--terminal` flag builds only Light Up and its required common C
units. Generated Web assets, validator and terminal executable are Git-ignored.
Build dependencies are Python 3 with tar extraction filters and a C compiler;
runtime requires the existing PHP CLI and Python 3. Upstream MIT notices are
retained in both surface directories as `LICENSE.upstream`.

## Boundaries

- `terminal_engine.c` owns no rules. It calls the pinned backend/midend for
  input, history, restart, serialization and completion. Drawing callbacks
  provide presentation flags for illumination and errors. Its private pipe
  protocol accepts keyboard commands and a length-prefixed canonical import.
- `native-doors/doors/tatham-terminal/play.py` renders the upstream ASCII board
  and presentation flags within 80x24. WASD/arrows move, Space places a bulb,
  `x` marks, `u`/`y` undo/redo, `r` restarts, `?` displays help, `v` saves,
  and `q` (also Ctrl-C/Ctrl-D) saves and returns. Color is unnecessary.
- `scripts/tatham-progress.php` is a CLI-only, per-launch JSON-lines helper.
  It accepts no command-line arguments. Trusted `DOOR_USER_NUMBER` resolves
  the active caller. Requests accept `acquire`, `renew`, `save` (payload and
  expected revision), and `release`. The owner token and attempt identity stay
  inside this helper; request-supplied user IDs and owner tokens are rejected.
- The helper calls `TathamProgressService` directly. Only PHP loads database
  configuration. The manifest launches Python through `env -i` with a minimal
  environment; `{user_number}` is substituted by the existing NativeAdapter
  from the authenticated session row, not from player input. This is a local
  trusted-launch contract, not authorization for arbitrary local shell users.

Both surfaces use the same reserved `tatham` slot and canonical validation.
Import and successful save acknowledgments must exactly match engine bytes.
Background checkpoints coalesce to approximately one per second; renewal is
approximately every ten seconds. Conflicts stop play without overwriting the
last acknowledgment. Explicit return waits for save and conditional release.
Hangup attempts safe save/release; an uncatchable exit relies on lease expiry.
Restart retains the same attempt and canonical history semantics.

## Launcher and proof boundary

The manifest is disabled and admin-only. The underlying Experience group is
`tatham`; `tatham-terminal` is its telnet surface, not a separate puzzle identity.
The existing bridge reads initial PTY dimensions from per-door admin configuration,
not the manifest's default config block. Future activation must set the Native
Door terminal size to `80x24` through Admin; the isolated proof sets this value
only in its disposable configuration. No platform launcher changes are required.

Focused native tests are `tests/Integration/TathamNativeProgressTest.py` and
require an explicitly disposable Slice 4 application fixture. M4 acceptance exercises normal username/password Telnet login, the real
Crossroads detail/launch path, the multiplexing bridge and NativeDoor, then
Save & Return back to Crossroads on the same connection. Web/Telnet boundary
saves compare byte-identically; canonical completion, stale-writer rejection,
hangup/reconnect and caller isolation are checked in an isolated application.
`tests/Integration/TathamBbsLaunchTest.php` guards grouped terminal admission;
`tests/Unit/ExperienceCompositionTest.php` guards Telnet transport selection.
The approximately 30-second wait after abandoning a Web lease is safe but
remains post-M4 polish. The Experience is not enabled for production callers.
