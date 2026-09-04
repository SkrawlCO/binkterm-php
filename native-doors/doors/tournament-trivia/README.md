# Tournament Trivia — NativeDoor (Crossroads Experience #5, M2)

Real-time multiplayer BBS trivia. Callers race to answer the same live question
first; the game also works as a cross-node chat room.

## Shape

Persistent-daemon + per-caller-client, the same pattern as MultiZork and
ascii-royale:

- **Shared server** `trivsrv` — one process, supervised in the `binkterm-app`
  image as `[program:tournament-trivia-srv]` (see
  `ops/docker/supervisord.conf` and `ops/docker/tournament-trivia/`). It owns
  all game state and talks to clients over POSIX message queues.
- **Per-caller client** `triv32` — this door. `launch-tournament-trivia.sh`
  runs the OFFICIAL upstream Door32 client on the caller's PTY:
  `triv32 -LOCAL -USERNAME "<DOOR_USER_NAME>" -NODE <DOOR_NODE> -GRAPHICS`,
  cwd `/var/lib/tournament-trivia`.

## Identity

`{user_number}` / `{user_name}` / `{node}` from the NativeAdapter (with
`DOOR_USER_NUMBER` / `DOOR_USER_NAME` / `DOOR_NODE` env fallbacks) — the real
authenticated BinkTerm caller and their unique session node. The launcher
validates the id, clamps the node to 1..999, and sanitises the username to a
single safe argv token. Nothing hard-coded.

`-LOCAL` (not DOOR32.SYS): OpenDoors' Door32 comm-handle path would need new
BinkTerm↔PTY comm wiring; `-LOCAL` gives OpenDoors direct console I/O on the
PTY and still carries the real identity. See the launcher header.

## Provenance / build

Upstream `EricOulashin/tournament-trivia` @ `b195ff2f9271e90d9804e60258d31225a712c96e`
(GPLv3) + Synchronet OpenDoors SDK `SynchronetBBS/sbbs` @
`74898075200f776fe8a4ed23b1b0085b93e2b729` (`src/odoors`, LGPL-2.1), built from
source with **no upstream modification** in the `tournament-trivia-build`
Docker stage. Full detail: `ops/docker/tournament-trivia/README.md`.

## M2 scope

Minimal NativeDoor path only. No Crossroads Curated classification, no icon, no
catalog polish — that is the next slice. No durable state: the monthly
scoreboard (`player.dat`) resets on image recreate.
