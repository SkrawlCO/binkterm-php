# BCR Games Server — Crossroads gateway

A [BinktermPHP Native Door](../../../docs/NativeDoors.md) that relays a caller to
**Black Country Rock's** publicly advertised Telnet endpoint as a single
Crossroads Experience.

Nothing here reimplements, copies, or scrapes BCR. The integration is a manifest
and a ~10‑line wrapper; the transport is the system `telnet` client (already
installed for the DOS/native door subsystem). BinktermPHP platform source is not
modified.

---

## Ownership

| Thing | Owner |
|---|---|
| From Here To Eternity, Freedom Train, 1NS0MN1A, Character Sheet, Trash Talking Wall, Leviathan, and the rest of the BCR service | **Shooter Jennings / Black Country Rock** |
| BCR accounts, characters, authentication, content, artwork, and branding | **Black Country Rock** |
| The `bcrgames.com:31337` endpoint | **Black Country Rock** |
| This wrapper (`bcr.sh`), the manifest, and the descriptive copy | L33TEST |

L33TEST does not claim authorship of anything past the endpoint. No BCR artwork
or branding is bundled — the Experience uses the standard Crossroads fallback
icon until an original L33TEST icon is created separately.

---

## How it works

`bcr.sh` is the whole integration layer:

```
telnet -E -K bcrgames.com 31337
```

- `-E` disables the Telnet escape character, so the caller can never break out to
  a local `telnet>` prompt.
- `-K` disables automatic login (`.netrc` / `TELNET_USER`), so **nothing about the
  BinkTerm user is offered to BCR**.

The caller lands on **BCR's own Login / Create Character screen**. BCR performs
all authentication. No BinkTerm username, real name, user id, password, or
drop‑file data is sent to BCR. The wrapper does not parse, scrape, transcribe, or
log any terminal input or output. One PTY / one outbound Telnet connection per
caller; the native‑door bridge owns the process lifecycle via `exec`.

This is **Telnet, not RLogin** — RLogin (RFC 1282) transmits an identity
handshake on connect and is deliberately not used.

### Surfaces

`terminal_mode: raw`, `output_encoding: utf8`. Both surfaces launch through the
same native‑door bridge and the same outbound Telnet connection:

- **Web** — the xterm.js terminal player at `/games/nativedoors/bcrgames`;
  returns to `/experiences/bcrgames`.
- **Telnet / SSH** — the Crossroads Experience catalog; runs under `raw` so
  ANSI passes through cleanly and window‑resize is propagated to BCR (NAWS).

### Multiplayer metadata

`experience.multiplayer` is **`false`**. BCR's games are multiplayer, but
Crossroads has no visibility into BCR's internal player state — two L33TEST users
who launch this gateway get independent BCR sessions with independent BCR
logins, and Crossroads cannot truthfully represent them as co‑players. Crossroads
only reports local relay facts (a session is active, who is connected, the local
relay‑session count, prior launches, session end).

---

## Enabling / reproducing this door

1. The system `telnet` client must be installed (it already is for the DOS/native
   door subsystem — Debian/Ubuntu: `apt-get install -y telnet`). A non‑standard
   path can be set with `BCR_TELNET_BIN` in `.env`.
2. This directory provides the manifest and the wrapper. Nothing else to copy.
3. In **Admin → Native Doors**: *Sync Doors*, then enable **BCR Games Server**
   with `credit_cost: 0` and `allow_anonymous` left off. (Or add a `"bcrgames"`
   entry to `config/nativedoors.json`.)
4. Restart the terminal daemon if you want it available over Telnet/SSH in an
   already‑running session:

   ```
   docker exec binkterm-app supervisorctl restart telnet
   ```
