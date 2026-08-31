# Tristam Island — Interactive Fiction Native Door

A [BinktermPHP Native Door](../../../docs/NativeDoors.md) that runs the parser
text adventure **Tristam Island** through the **Frotz** Z-machine interpreter,
as an ordinary authenticated Crossroads Experience on the Web and Telnet/SSH
surfaces.

Nothing about this door required changing BinktermPHP platform source. It is a
manifest, a small wrapper script, and read-only story content. The interpreter
is a separate system package the sysop installs.

---

## Ownership and licensing

Three separate things, three separate owners. **None of them is owned by
BinktermPHP or L33TEST.**

### 1. The story — `story/tristam-en.z3`

* **Title:** Tristam Island
* **Author:** Hugo Labrande
* **First released:** 20 November 2020 (this Z-machine v3 file is Release 5,
  serial 220830)
* **License:** **Creative Commons Zero v1.0 Universal (CC0-1.0)** — a public
  domain dedication. The author "overtly, fully, permanently, irrevocably and
  unconditionally waives, abandons, and surrenders all … Copyright and Related
  Rights". Copying, hosting, modifying and redistributing are unconditionally
  permitted. The full CC0 legal code is in [`LICENSE`](LICENSE).
* **Provenance:** obtained from the author's own canonical repository,
  <https://github.com/hlabrand/tristam-island> (branch `main`), files
  `tristam-en.z3` and `LICENSE`, on 2026-08-31.
  `sha256(tristam-en.z3) = d178078af04528be0dbc3bb41743ca44d5436b78f10e8ca99e95fddc0a4c2b0f`
* **Not included / not redistributed here:** the game's cover art and feelies
  (postcard, "MI-5" dossier, invisiclues, guide) are **copyright Karen Christie
  & Stephen F. Winsor** and are *not* CC0. This door ships only the text story
  file, which contains no artwork (Z-machine v3 has no graphics).

Author's own words (repository `README`): *"The game is now open source and
under a Creative Commons Zero licence. … Please enjoy the documents here, fork
this repository, create your own games, modify this one, etc."* — Hugo Labrande

### 2. The interpreter — Frotz / `dfrotz`

* **Project:** Frotz, dumb (non-curses) interface, maintained by David Griffith.
  Homepage <https://davidgriffith.gitlab.io/frotz/>, source
  <https://gitlab.com/DavidGriffith/frotz>.
* **License:** **GNU General Public License, version 2 or (at your option) any
  later version (GPL-2.0-or-later).**
* **Not bundled.** Frotz is installed by the sysop as the Debian/Ubuntu system
  package `frotz` (which provides `/usr/games/dfrotz`), exactly like `dosbox-x`
  is installed for DOS doors. This door only *invokes* it.

### 3. This door's integration glue — `run.sh`, `nativedoor.json`

Written for BinktermPHP. May be reused/redistributed with the project. Contains
no story text and no interpreter code.

---

## How it works

`run.sh` is the whole integration layer:

1. **Registered members only.** Requires a numeric `DOOR_USER_NUMBER`
   (the BBS `users.id`). The manifest does not set `allow_anonymous`, so guest
   launches are already refused by the platform; the wrapper double-checks.
2. **Private, durable saves.** All mutable interpreter I/O — in-game `SAVE`,
   `RESTORE`, `SCRIPT` (transcript) and command `RECORDING` — is confined to
   `"$DOOR_HOME/saves"` via `dfrotz -R <dir>`, the interpreter's own documented
   restricted-read/write mode (`dfrotz(6)`). `$DOOR_HOME` is
   `data/users/<users.id>/tristam/`, created by the Native Door bridge before
   launch. Saves survive across reconnects and across nodes because they are
   keyed by user id, not by node or session.
3. **No second lifecycle.** The wrapper `exec`s `dfrotz`, so the existing PTY
   bridge owns the process. Typing `quit` (or the Frotz exit hotkey) ends the
   interpreter, which ends the session, which returns the player to Crossroads.

No shell escape, menu, launcher, database, HTTP call, or identity handling is
added. Dumb Frotz has no shell-escape or arbitrary-file verbs; the only file
operations are the in-game save verbs, which `-R` contains.

### Command

```
dfrotz -R "$DOOR_HOME/saves" -m  story/tristam-en.z3
```

| flag | why |
|------|-----|
| `-R <dir>` | Restrict every read/write to `<dir>` and nowhere else (`dfrotz(6)`). This is the save-confinement guarantee. |
| `-m` | Suppress the interpreter's MORE prompts — the BBS terminal does its own scrolling. |
| *(no `-w`)* | Text width is read from the PTY. |
| *(no `-f` / `-p`)* | Plain UTF-8 text output, no markup (`output_encoding: utf8` in the manifest). |

---

## Surfaces

* **Web** — appears in Crossroads; launches in the xterm.js terminal player.
* **Telnet / SSH** — appears in the terminal-server Crossroads catalog; runs
  under `terminal_mode: raw` so the plain text passes through cleanly and
  window-resize is propagated.

`hide_from_web` is **not** set, so both surfaces are enabled.

---

## Installing / reproducing this door on another BinktermPHP system

1. Install the interpreter (once, system-wide — same layer as `dosbox-x`):

   ```
   apt-get install -y frotz          # provides /usr/games/dfrotz
   ```

   For a container deployment, add `frotz` to the image's package list next to
   `dosbox-x` so it survives a rebuild.

2. This directory (`native-doors/doors/tristam/`) provides the manifest, the
   wrapper and the CC0 story. Nothing else to copy.

   To fetch the story from source instead of using the bundled copy:

   ```
   curl -L -o story/tristam-en.z3 \
     https://raw.githubusercontent.com/hlabrand/tristam-island/main/tristam-en.z3
   # expected sha256: d178078af04528be0dbc3bb41743ca44d5436b78f10e8ca99e95fddc0a4c2b0f
   ```

3. In **Admin → Native Doors**: *Sync Doors*, then enable **Tristam Island**
   with `credit_cost: 0`, `allow_anonymous` left off. (Or add a `"tristam"`
   entry to `config/nativedoors.json`.)

4. Restart the terminal daemon if you want it available over Telnet/SSH in an
   already-running session:

   ```
   docker exec binkterm-app supervisorctl restart telnet
   ```

Saves for user *N* live in `data/users/N/tristam/saves/`. That directory is
runtime data — never committed, never shared between users.
