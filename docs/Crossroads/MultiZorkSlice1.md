# MultiZork Slice 1: the generic line-relay capability

This document describes two things, kept deliberately separate:

1. **The generic line-buffered private-TCP relay** — a small, reusable
   extension to BinkTermPHP's existing native-door terminal handling. Any
   SysOp with a plain, line-oriented TCP service they want to reach from a
   native door manifest can use this, with no MultiZork knowledge required.
2. **The thin MultiZork adapter** — L33TEST/Crossroads product code that
   uses capability (1) to onboard players into one specific persistent-world
   game (MultiZork). It is not part of BinkTermPHP core and is not a
   generic "door adapter framework."

This is Crossroads Experience #1's Slice 1: an architecture proof, not a
finished product feature. See `docs/EXPERIENCE_ARCHITECTURE.md` for the
platform-wide Experience model this reuses unchanged.

## 1. The generic capability

### What it is

A native door manifest (`native-doors/doors/<id>/nativedoor.json`) can set:

```json
"door": {
    "executable": "<any label — unused by this mode, but required by the manifest schema>",
    "terminal_mode": "line",
    "relay_host": "127.0.0.1",
    "relay_port": 12345,
    "relay_adapter_class": "Fully\\Qualified\\ClassName"
}
```

When `terminal_mode` is `"line"` (a native-only value, alongside the
existing `"doorway"`/`"raw"`), `telnet/src/DoorHandler.php` launches the
door through `launchLineRelayDoor()` instead of the existing dosbox-bridge
WebSocket relay (`launchDoor()`/`relayLoop()`). It:

1. Calls the same `/api/door/launch` and `/api/door/end` routes every
   other door type uses — session admission, node allocation, the
   `door_sessions` row, `ExperiencePresence`, and door-play activity all
   work exactly as they do for DOS/native/RLogin doors today. Nothing
   about that plumbing changed.
2. Connects directly to `relay_host:relay_port` via a plain PHP TCP socket
   (`stream_socket_client`) — **no local process is spawned, and the
   dosbox-bridge Node.js multiplexing server is not involved.** This mode
   exists for services that are already a reachable, persistent TCP
   listener (unlike DOSBox or an outbound `ssh` tunnel, which need
   something local to spawn).
3. Runs `lineRelayLoop()`: local echo, Backspace/Delete erase, Enter
   submits a complete line LF-terminated to the socket, and the service's
   own output is written back to the terminal largely unchanged (no
   Doorway/ANSI-to-scancode translation — this mode is for plain
   scrolling-text services, not screen-oriented ones). CRLF/bare-CR/
   CR-NUL/Telnet IAC negotiation are handled entirely by BinkTermPHP's
   existing, shared `BbsSession::readKeyWithTimeout()`/`readRawChar()`
   normalization layer — this mode does not re-parse raw bytes itself.

### The optional adapter hook

If `relay_adapter_class` names a class with a callable static
`handshake(resource $conn, resource $sock, array &$state, array $context): void`
method, it runs once, directly against the raw socket, before the
transparent relay loop begins. If that class also has a callable static
`onOutput(string $chunk, array $state, array $context): void` method, it
is called with every chunk of service output exactly as written to the
terminal — a read-only observer, useful for watching for a pattern (e.g. a
returned credential) without altering the stream.

`DoorHandler` has no built-in knowledge of what an adapter class does, or
that MultiZork exists — it resolves the class purely by the name in the
manifest. Omitting `relay_adapter_class` gives plain transparent relay
with no handshake, which is a reasonable default for a SysOp who just
wants raw passthrough to a private line service.

### Security/endpoint assumptions

- `relay_host`/`relay_port` are SysOp-configured manifest fields, not
  user input — the relay destination is never chosen by the connecting
  caller.
- This mode does not create any listener; BinkTermPHP only ever connects
  *out* to the configured endpoint. Keep that endpoint private/loopback
  (or otherwise firewalled) unless you specifically intend it to be
  reachable another way — BinkTermPHP does not add any access control
  in front of it beyond ordinary Experience admission (admin-only,
  credit cost, capacity) at the door-launch layer.
- The relay loop bounds its input buffer (`DoorHandler::LINE_RELAY_MAX_LINE`)
  and drains service output before waiting on the next keystroke, so a
  chatty or silent service cannot starve the other side.

## 2. The MultiZork adapter (L33TEST-owned)

`BinktermPHP\Crossroads\MultiZorkAdapter` is the only place in the
codebase that knows MultiZork's own prompt vocabulary and access-code
mechanics (established by a prior disposable runtime proof against the
canonical, MIT-licensed Zork I Revision 88 story). It is Slice 1 scoped:

- **One fixed test expedition** (`MultiZorkAdapter::FIXED_TEST_EXPEDITION_ID`)
  — Slice 1 does not model multiple expeditions/instances.
- **Invisible return only.** On a connection where the BinkTerm user has
  no stored access code yet, the adapter does nothing but relay
  MultiZork's own banner — the human sees and drives the ordinary
  create/join/go flow, exactly as a direct MultiZork connection would
  show it. Only a *returning* caller with a stored code is automated, and
  only after `MultiZorkAccessRateLimit` allows it (MultiZork's own daemon
  does not throttle wrong access-code guesses, so this codebase must).
- **Credential storage** (`BinktermPHP\Crossroads\MultiZorkAccessMapping`,
  tables `multizork_expedition_credentials` / `multizork_access_attempts`)
  is a narrow, L33TEST-owned mapping: one BinkTerm user + one fixed test
  expedition → one opaque MultiZork access code. It is **not** a generic
  external-identity/credential capability — see the header comments on
  those classes for the reasoning, and promote/generalize only if a
  second real consumer needs the same shape. The access code is never
  surfaced through ordinary UI/API responses or logging.
- **No expedition naming, invitations, rosters, chat, transcript UI, or
  game-over UX.** Those are explicitly out of scope for this slice.

### What is NOT in the adapter

Generic Telnet/CR/LF/backspace handling, BinkTerm authentication, generic
secret storage mechanics, generic rate limiting, and generic presence are
all reused from existing BinkTermPHP capability (see Recon Areas 1–8 in
the prior architecture recon) — the adapter calls into that plumbing, it
does not reimplement any of it.

## Deploying/testing this Experience

`config/nativedoors.json` is a site-local, gitignored config file (like
`binkp.json`/`bbs.json`) — enabling a native door normally happens through
**Admin → Native Doors**, not by hand-editing this file. The
`multizork-slice1-test` entry used to validate this slice was added
directly for disposable testing; treat that as a one-off, not the
supported path for enabling a real Experience.

The manifest ships with `requirements.admin_only: true` and
`hide_from_web: true` — this Experience is not intended to appear for
ordinary users or on the web surface in Slice 1.
