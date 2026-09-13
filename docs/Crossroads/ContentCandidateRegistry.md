# Crossroads Content Candidate Registry

**Purpose:** ONE compact source of truth answering *"have we already seen this
thing, and what happened to it?"* — nothing else. Not a strategy document, not
a ranked board, not a historical narrative. Load this before every future
content hunt.

**Created:** 2026-09-12. **Expanded:** 2026-09-12 (same day, second pass) to
absorb the 2026-08-31 ledger and correct two dispositions caught rediscovering
themselves in a fresh content hunt (ssHattrick, AsciiArena).

## Source precedence (newest explicit disposition wins)

1. Explicit human/project verdict 2026-09-06 through 2026-09-12 (durable
   evidence only — this pass found none beyond the ssHattrick/AsciiArena
   correction itself).
2. `L33TEST_Crossroads_Master_Continuity_2026-09-05.md`
3. `Crossroads_Experience_Prospecting_Ledger_2026-09-04.md`
4. `Crossroads_Experience_Prospecting_Ledger_2026-08-31.md`

Older files remain useful for candidate identity/aliases/negative memory even
when their ranking, strategy, or "prospecting closed" framing is stale.
**Broad prospecting is open again** (Matt reopened the content search
2026-09-12) — do not cite the 2026-09-05 "prospecting closed" language as
current direction. That does not change any individual candidate's
disposition below; it only means new hunts are allowed to run.

`L33TEST_Reconstructed_Development_Action_Map_2026-09-05.md` was named as a
possible source this pass but was not found on disk; not used.

---

## GATE 0 — BEFORE SURFACING ANY CONTENT CANDIDATE

1. Normalize the candidate name against Canonical + Aliases below.
2. Check this registry.
3. If `LIVE` / `INSTALLED` / `SELECTED` / `TAGGED` / `WATCH` / `SHELVED`:
   candidate is **KNOWN** — do not present it as a discovery. It may still be
   picked for deliberate future recon, but describe it as a **known
   candidate**, never a **new discovery**.
4. If `REJECTED`: suppress unless the search found a **material new fact**
   that directly satisfies the row's explicit "Reopen Only If" condition. A
   new version number, a new commit, or the search simply finding it again is
   NOT a reopen condition.
5. If `NEVER_SURFACE`: suppress completely, no exceptions, unless Matt
   explicitly asks for it by name.
6. Only a candidate with **no matching canonical name or alias here at all**
   may be presented as new.

**Verdict completion rule:** a content assay is not complete until this
registry is updated, in the SAME transaction, with the resulting disposition.
This is mandatory — it is what stops the file going stale again.

---

## Status vocabulary

| Status | Meaning |
|---|---|
| `LIVE` / `INSTALLED` | Already part of L33TEST. Never present as a new discovery. |
| `SELECTED` | Chosen for current/future integration; not a discovery. |
| `TAGGED` | Known candidate worth retaining; don't rediscover as new. |
| `WATCH` | Known candidate waiting on a specific future fact. |
| `SHELVED` | Previously viable, deliberately parked. |
| `REJECTED` | Do not resurface unless a material new fact directly addresses the reason. |
| `NEVER_SURFACE` | Suppress completely unless Matt explicitly asks for it by name. |

Historical-term mapping used below: HIGH-INTEREST→usually `TAGGED`;
PASS/NOT ADVANCED/OUT OF SCOPE→`REJECTED` unless clearly temporary;
INVESTIGATE/RESEARCH→`WATCH`; ARCHAEOLOGY→`WATCH` or `TAGGED` depending on
whether a specific blocker exists; KEEP/Game-Hall-prospect→`TAGGED` unless
already `LIVE`.

---

## Registry — LIVE / INSTALLED / SELECTED

| Canonical | Aliases | Status | Reason | Last Seen | Reopen Only If |
|---|---|---|---|---|---|
| MultiZork | — | LIVE | Curated Experience #1, complete/persistent, special anti-MUD exception (not precedent). | 2026-09-05 | N/A |
| ASCII-ROYALE | ascii-royale | LIVE | Curated Experience #2, complete/live. | 2026-09-05 | Production defect only. |
| OpenGlad | openglad/openglad | LIVE / SELECTED | Curated Experience #3, complete/live (self-hosted WASM, WebDoor). | 2026-09-05 | Production defect only. |
| Chessmata | — | LIVE | Curated Experience #4, Web↔terminal shared game path complete/live. | 2026-09-05 | N/A |
| Tournament Trivia | — | LIVE | Curated Experience #5, complete/live; Aug-31 ledger's "lowest-friction proof" recommendation already executed. | 2026-09-05 | N/A |
| SyncDOOM | — | LIVE | Game Hall complete/live; explicit Matt exception to the no-DOOM-clones rule; not Curated. | 2026-09-05 | Production defect only. |
| Galactic Bloodshed | — | LIVE (Game Hall) / REJECTED (Curated) | Game Hall integration complete/live; Curated bid failed on human onboarding/discoverability, not tech. | 2026-09-05 | Curated: only if a genuine onboarding/discoverability redesign is proposed. |
| Usurper Reborn | Usurper Reborn v1.0.3 | LIVE (Game Hall) | Native BinkTerm door, embedded-worldsim online mode, live. | 2026-09-10 | N/A |
| DrugLord | — | LIVE | L33TEST's existing classic Dope-Wars-family door; context for the `dopewars` (networked) row below. | — | N/A |

## Registry — TAGGED (known, retained, not currently blocked)

| Canonical | Aliases | Status | Reason | Last Seen | Reopen Only If |
|---|---|---|---|---|---|
| DECWAR | — | TAGGED | 1978 PDP-10 multiplayer space war under modern emulation, Telnet-playable; no later disposition found — still an open, credible candidate. | 2026-08-31 | N/A (open for recon whenever prospecting resumes). |
| Riftborne | — | TAGGED | Asynchronous persistent colony/fleet/diplomacy strategy; server/SSH claims need verification but no reject recorded. | 2026-08-31 | N/A |
| Wolfpack Empire | — | TAGGED | Persistent nation-sim, pure Telnet; named "already-known material" in Sep-4 final pass, not rejected. | 2026-09-04 | N/A |
| terpad / Pictionary | — | TAGGED | Shared drawing/Pictionary social game; very new, maturity/geometry untested but not rejected. | 2026-08-31 | N/A |
| ssh.place | — | TAGGED | Persistent shared terminal canvas (r/place over SSH); viewport behavior is the open gate. | 2026-08-31 | N/A |
| Life Simulator | — | TAGGED / EXPERIMENTAL | Communal curses cellular-automata/ecology lab; 80x25-ish usability untested. | 2026-08-31 | N/A |
| Land of Devastation | — | TAGGED / ARCHAEOLOGICAL | Post-apoc BBS world; ANSI/ASCII interface exists, old binary/emulation path is the gate. | 2026-08-31 | N/A |
| The Clans | — | TAGGED | GPL RPG/strategy/InterBBS hybrid. Explicitly **reopened by Matt** on 2026-09-04 despite an earlier dislike verdict. | 2026-09-04 | N/A (already reopened). |
| Castle Wars | — | TAGGED | Head-to-head card/castle duel, browser+SSH TUI; licensing/account/maturity review outstanding, not rejected. | 2026-08-31 | N/A |
| SSH-Tron | sshtron, zachlatta/sshtron | TAGGED / LIGHTWEIGHT | Instant multiplayer lightcycle over SSH; small but legible. | 2026-08-31 | N/A |
| clue.ssh | — | TAGGED | Multiplayer Clue-like social deduction; parody-theme taste/IP review outstanding. | 2026-08-31 | N/A |
| One Way Out | — | TAGGED / DEEPER RECON | Cooperative one-dimensional roguelike; rejoin/death semantics + license unverified. | 2026-08-31 | N/A |
| Shellcade | — | TAGGED | Live SSH/browser terminal arcade hub; prefer surfacing individual games below over the hub. | 2026-08-31 | N/A |
| Voidrunners | (a Shellcade title) | TAGGED | 1–6p real-time space shooter, part of Shellcade. | 2026-08-31 | N/A |
| Salvo | (a Shellcade title) | TAGGED | 1–6p artillery, part of Shellcade. | 2026-08-31 | N/A |
| Paperdrift | (a Shellcade title) | TAGGED | 1–6p glider race, part of Shellcade. | 2026-08-31 | N/A |
| Neon Snake | (a Shellcade title) | TAGGED | 1–2p snake, part of Shellcade. | 2026-08-31 | N/A |
| Falcon's Eye | — | TAGGED | County-management/InterBBS strategy door by the BRE author. **Matt owns a legitimate registered copy** — narrow commercial exception, not precedent. | 2026-09-04 | N/A |
| Kannons & Katapults Remastered | — | TAGGED | 2025 remaster; async persistent PvP, concurrent matches, PvP rankings. | 2026-09-04 | N/A |
| BBS Drag Racing | BBS Drag Racing 2.40 | TAGGED | 2025 modernization; simultaneous online races, rankings. | 2026-09-04 | N/A |
| Asciifarm / rustifarm | — | TAGGED | Persistent shared-world ASCII farming/fighting for pubnix/shared SSH; Python + Rust rewrite. | 2026-09-04 | N/A |
| Eressea | — | TAGGED | Long-running open-ended persistent faction strategy, Atlantis-descended but decades-divergent; ancestry is a flag, not a kill. | 2026-09-04 | N/A |
| Empires at War | Empires at War v2.53 | TAGGED | Six-power Napoleonic empire game, InterBBS-capable; only clear new Curated-pool addition from the Sep-4 final search pass. | 2026-09-04 | N/A |
| Rockin Radio | — | TAGGED | Game Hall prospect; Matt explicitly requested retention. Multinode DJ role-play. | 2026-09-04 | N/A |
| BBS Simulator | — | TAGGED | Game Hall prospect; BBS-within-a-BBS SysOp sim. | 2026-09-04 | N/A |
| Kingdoms | — | TAGGED | Historical InterBBS federation design (remote-BBS castles as local players); preserved for federation lessons, not itself an install target. | 2026-08-31 | N/A |
| Cursed | — | WATCH | Terminal tabletop/GM concept kept; the specific implementation assayed was too fragile/WIP to ship. | 2026-08-31 | A materially more complete implementation appears. |

## Registry — WATCH (known, waiting on a specific fact)

| Canonical | Aliases | Status | Reason | Last Seen | Reopen Only If |
|---|---|---|---|---|---|
| TomeNET | TomenetGame/tomenet | WATCH | High interest but licensing/IP concern (non-free Moria + TRACE-derivative + unlicensed Tolkien content); no further work without request. | 2026-09-03 | Explicit request only, or the licensing concern is resolved. |
| Dank Domain | theflyingape/dankdomain | WATCH | Game-Hall-leaning; real telnet+web MIT door, but unfixed maintainer-dismissed SQLi + bus-factor-1 + 4th BBS-door-RPG weak distinctiveness. Locked. | 2026-09-03 | Explicit request only. |
| dopewars (networked) | benmwebb/dopewars | WATCH | Best candidate of the Exp#3 campaign; runtime-proven MP/AI/market/chat/indirect-PvP; "wanted another round" = YES. Collides with "no more classic doors" rule; at best Game Hall. Operator decision pending. | 2026-09-03 | Matt authorizes the bounded 2-human+3-AI play-assay, or explicitly settles it another way. |
| Conquest | Conquest BBS/Synchronet | WATCH | **Conflict** — Sep-4 ledger lists it under Final Rejects ("previously rejected; do not resurrect"), but Sep-5 master continuity instead says "Conquest requires credible Telnet path" (open gate). Newest (Sep-5) kept as current status. | 2026-09-05 | A credible Telnet/SSH path is demonstrated. |
| Iron Ox | — | WATCH | Real-time InterBBS strategy w/ programmable drones; existed on an older board, later disposition lost. Sep-5: "disposition unresolved — do not invent one." | 2026-09-05 | Historical disposition is recovered, or Matt explicitly re-opens it. |
| Dark Lands | darklands.cx | WATCH | Open-source hosted multiplayer text RPG w/ BBS-door support. Classification pending — kill under anti-MUD rule if it proves fundamentally a MUD. | 2026-09-04 | MUD-vs-not classification is resolved in its favor. |
| Thieves' Guild | — | WATCH | Atari ST door, async shared persistent map/leaderboard, Pascal source preserved. Gates: provenance/rights, modern viability. | 2026-09-04 | Provenance/rights + modern viability established. |
| Opicron colony/strategy door | op-ctn11.zip | WATCH | June-2026 Demonic/Opicron colony/negotiation door; exact title unresolved — do not invent it or assume a Catan relationship. | 2026-09-04 | Exact title/source confirmed. |
| Iron War | Iron War v1.07 | WATCH | Corporations run Mars bases; free registration key survives; source/rights and MP depth unresolved. | 2026-09-04 | Source/rights + MP depth confirmed. |
| The Magic Gate | — | WATCH | Multinode medieval-town RPG w/ world-building scripting; rights/source unclear. | 2026-09-04 | Rights/source clarified. |
| Star Fight | — | WATCH | Galactic empire game w/ colonization/industry/politics/real-time radio; source/rights unresolved. | 2026-09-04 | Source/rights clarified. |
| Scavenger Hunt | — | WATCH | Competitive shared-state finding/stealing/hiding + traps; source/rights unresolved. | 2026-09-04 | Source/rights clarified. |
| Space Dynasty | Space Dynasty Elite | WATCH | MP resource/interstellar strategy modernization; provenance unresolved. | 2026-09-04 | Provenance clarified. |
| BotWars | — | WATCH | BBS compatibility evidence but exact identity/source unresolved. | 2026-09-04 | Identity/source clarified. |
| RDQ3 | — | WATCH | Distinct door referenced in ANetBBS material; identity/game proposition unresolved. | 2026-09-04 | Identity clarified. |
| Nouron | — | WATCH | Exact repository/identity unresolved. | 2026-09-04 | Identity clarified. |
| HackOS | — | WATCH | Planned persistent async hacking MMO w/ trading/sabotage/factions; unreleased, real terminal operability unproven. | 2026-08-31 | Real release + demonstrated terminal operability. |
| Textorio | — | WATCH | ASCII factory/automation concept; must prove real terminal I/O + MP maturity. | 2026-08-31 | Terminal I/O + MP maturity demonstrated. |
| Fenrok Online | — | WATCH | Persistent terminal MMO concept (dungeons/crafting/guilds); still incomplete. | 2026-08-31 | Completion/maturity demonstrated. |
| Terminal Space | (NOT "Terminal Space Program" — separate candidate, do not conflate) | WATCH | MP space trading/exploration; maturity uncertain. | 2026-08-31 | Maturity demonstrated. |
| Terminal Velocity | — | WATCH | Reported SSH-playable MP space trading/combat w/ persistence/procgen; needs first-principles verification. | 2026-08-31 | Verified hands-on. |
| CORRUPTMIND | — | WATCH | Lovecraftian MP hardcore roguelike/RPG over SSH; closed beta. | 2026-08-31 | Public/open access appears. |
| MegaMOO | — | WATCH | Inhabitants build/program the persistent MP world from inside it over Telnet/WebSocket; public MP maturity not established. | 2026-08-31 | MP maturity established. |
| PLATO Avatar | — | WATCH | Historically important MP dungeon RPG; ASCII adaptation evidence exists, practical external gateway unproven. | 2026-08-31 | Gateway proven. |
| PLATO Empire | — | WATCH | Living historical team space-warfare community; specialized PLATO protocol is the gate. | 2026-08-31 | Protocol/gateway solved. |
| Netrek | — | WATCH | Major historical team space-warfare lineage; needs a maintained curses/ASCII client for current servers. | 2026-09-12 | A maintained modern curses/ASCII client is confirmed. |
| Crossfire RPG | — | WATCH | Mature cooperative MP action RPG; no maintained character-terminal client established. | 2026-08-31 | A character-terminal client is established. |
| Bunnyland | — | WATCH | Persistent multi-surface ECS world; compelling gameplay not yet established. | 2026-08-31 | Gameplay depth demonstrated. |
| terminal-minceraft | — | WATCH | Minecraft/Eaglercraft via terminal escapes; licensing/complexity/quality all major gates. | 2026-08-31 | Licensing + quality gates cleared. |
| SSH Minesweeper | — | WATCH | Unverified whether "multiplayer" means actual player interaction. | 2026-08-31 | Real player-interaction confirmed. |
| tetris-terminal | — | WATCH | Modern RT 1v1 terminal Tetris over WebSocket; classic-terminal geometry must pass. | 2026-08-31 | 80x24-class geometry confirmed. |
| Shell Arena | — | WATCH | SSH/browser MP card battler w/ lobby/leaderboard/replay; maturity needs verification. | 2026-08-31 | Maturity verified. |
| Czarwars | — | WATCH / ARCHAEOLOGY | Large MP strategic space environment (sectors/wormholes/starbases); needs executable/source/interface/persistence archaeology. | 2026-08-31 | Archaeology completed with a viable path. |
| Assault I | — | WATCH / ARCHAEOLOGY | 2-player TCP/IP strategy/wargame; insufficient gameplay evidence to disposition further. | 2026-08-31 | More gameplay evidence surfaces. |
| Death From Above | — | WATCH / ARCHAEOLOGY | Historical MP artillery/cannon game; insufficient evidence. | 2026-08-31 | More evidence surfaces. |
| Old Unix archive leads | civ, boa, codewar | WATCH / ARCHAEOLOGY | Bare names to research, not promoted candidates. | 2026-08-31 | Any one of them accumulates real evidence of a playable game. |
| Bulls & Cows | — | WATCH | Multiplayer rewrite explicitly "in progress" as of Aug-31 — not yet a candidate. | 2026-08-31 | The multiplayer rewrite ships. |
| snakes.run | Secure Snake Home, eieio.games snake, Nolen Royalty snake | WATCH | **NEW (2026-09-12, Content Hunt v2, Pass A — Charm/Bubble Tea showcase channel).** Real, live, massively-multiplayer snake over SSH (`ssh snakes.run`), thousands of concurrent players, launched Feb 2026, active/blogged technical writeups since. Genuinely fun novelty/spectacle, but license and self-host path are both unconfirmed — no public source repo found; appears to be the author's own hosted-only project. | 2026-09-12 | A public license/source repo is confirmed (self-host path), or Matt explicitly wants a portal-style link-out despite no self-host. |

## Registry — SHELVED

| Canonical | Aliases | Status | Reason | Last Seen | Reopen Only If |
|---|---|---|---|---|---|
| Tangaria | Elsewhere, Tangaria/Elsewhere | SHELVED | Persistent-world path affected by upstream developer becoming unresponsive; fully installed/proven once, shelved, not a new discovery. | 2026-09-05 | Developer responsiveness/activity materially changes. |
| Terminal Dungeon | — | SHELVED | Munchkin-related asset/content licensing unresolved. | 2026-09-05 | Licensing clarified/resolved. |
| Evscaperoom | — | SHELVED | Recorded as "shelved/reject" — ambiguous combined language in the source doc; treated conservatively as do-not-resurface. | 2026-09-05 | Explicit Matt re-review only. |
| MajorMUD portal | — | SHELVED | Not active; do not suggest as next work. | 2026-09-05 | Explicit Matt re-review only. |

## Registry — REJECTED

| Canonical | Aliases | Status | Reason | Last Seen | Reopen Only If |
|---|---|---|---|---|---|
| ssHattrick | sshattrick, ricott1/sshattrick, "Hattrick over SSH" | **REJECTED** | **Corrected 2026-09-12.** 2026-08-31 ledger: 160x50 minimum geometry — poor classic-BBS fit. (Earlier same-day registry pass mis-marked this `TAGGED` for lack of a located disposition; the Aug-31 ledger supplies the actual verdict.) | 2026-08-31 | Only if Matt explicitly requests reconsideration under a newer Experience-specific expanded-geometry policy, or a materially different presentation/geometry path appears. Rediscovery by search is NOT a reopen condition. |
| AsciiArena | ascii-arena, lemunozm/asciiarena, tapio/asciiarena | **REJECTED** | **Corrected 2026-09-12.** 2026-08-31 ledger: incomplete/stale demo. (Name is shared by ≥2 unrelated repos; alias list may span more than one actual project — treat any of them as this row until proven otherwise.) | 2026-08-31 | Only if the project materially matures into a maintained, substantially playable game. A new commit/version alone is not enough. |
| Rebels in the Sky | — | REJECTED | Aug-31 was `TAGGED`; later hands-on assay (2026-09-02) found FINAL FAIL on the classic-terminal presentation gate (~160x48 design, TrueColor-only art, clips/degrades at 80x24). Newest verdict kept. | 2026-09-02 | A real 80x24/ANSI-degradable presentation path is demonstrated (not merely a new release). |
| Immortal Barons | — | REJECTED | Aug-31 said "reconsider if surfaced" (had been held back on an overgeneralized Nostrian-Conquest comparison); Sep-4 raw pool listed it as open; **Sep-5 master continuity records the final "reject."** Newest wins. | 2026-09-05 | A material new fact addresses whatever the Sep-5 reject actually turned on (not independently reconstructable from this row — re-check before reopening). |
| Botany | jifunks/botany | REJECTED | Technical assay (M3) passed, but Matt decisively rejected the gameplay afterward. | 2026-09-05 | Only if the gameplay itself materially changes in the area Matt rejected — not a code/version bump. |
| Alpha Force CLI | SSH Fighter, @ajaxdavis | REJECTED | Gameplay + presentation + provenance failed; needs 144x50. | 2026-09-03 | Real terminal-geometry fix + provenance resolved. |
| NSnipes | theboffin/nsnipes | REJECTED | Sparse gameplay, players can't find each other, pre-alpha. | 2026-09-03 | Material upstream gameplay maturity change. |
| DEN | andreas-jonsson/den, "phix" | REJECTED | Zero bots, pure PvP, dead project, one shallow level. | 2026-09-03 | Project revival with real content. |
| Daste745/tui-game | — | REJECTED | 1Hz flicker render, inert bots, no goal; Aug-31: "too primitive." | 2026-09-03 | Material rebuild of the render/gameplay loop. |
| Asterion | ricott1/asterion | REJECTED | Hard 160-column minimum; blind corridor-crawl; TrueColor-only. | 2026-09-03 | A real 80x24/ANSI-256 path appears. |
| Hack of Life | isharacomix/hack-of-life | REJECTED | Netplay desyncs (clients disagree on score 20x); no AI for solo. | 2026-09-03 | Netcode fix upstream + solo AI added. |
| sshOuroboros | MShel/sshOuroboros | REJECTED | No LICENSE anywhere (all rights reserved) — pure legal block; structurally the most promising model of its search round, never built/run. | 2026-09-03 | Author publishes a valid compatible license. |
| Suzerain | — | REJECTED | Architecture passed, gameplay failed (final). | 2026-09-03 | Material gameplay rework upstream. |
| Astra Protocol 2 | — | REJECTED | Aug-31 HIGH-INTEREST (cooperative crew starship) → explicitly named in the Sep-4 Final Rejects list. Newest wins. | 2026-09-04 | A material new fact from the Sep-4 rejection resurfaces favorably (commercial ownership/remote-player rights were the original gates). |
| BSD Hunt | — | REJECTED | Aug-31 HIGH-INTEREST (maze combat) → named in the Sep-4 Final Rejects list. Newest wins. | 2026-09-04 | Material new fact addressing the Sep-4 reject. |
| Core War | Core War BBS door, pMARS | REJECTED | Aug-31 HIGH-INTEREST → named in the Sep-4 Final Rejects list ("Core War/pMARS"). Newest wins. | 2026-09-04 | Material new fact addressing the Sep-4 reject. |
| NetHack | NetHack/dgamelaunch | REJECTED | Aug-31 tagged as an asynchronous-design proof → named in the Sep-4 Final Rejects list. Newest wins; also conventional-MUD/roguelike-adjacent overlap. | 2026-09-04 | Material new fact addressing the Sep-4 reject. |
| MAngband | — | REJECTED | Aug-31 backup/reference behind TomeNET → named in the Sep-4 Final Rejects list. Newest wins. | 2026-09-04 | Material new fact addressing the Sep-4 reject. |
| Final Battle | finalbattle | REJECTED | Sep-4 raw pool listed it open (provenance/terminal-path gates); **Sep-5 master continuity records the final "reject."** Newest wins. | 2026-09-05 | Material new fact addressing the Sep-5 reject. |
| PENTASIM | — | REJECTED | Sep-4 raw pool listed it open (scripting proven, persistent-world authoring not); **Sep-5 master continuity records the final "reject."** Newest wins. | 2026-09-05 | Material new fact addressing the Sep-5 reject. |
| Time Machine | — | REJECTED | Sep-5 master continuity: "DEAD/REJECTED/REMOVED, never resurrect." Strongest reject language on file short of the permanent-suppress row. | 2026-09-05 | Explicit Matt request only — treat as effectively permanent. |
| Virtual Sysop | Virtual Sysop III | REJECTED | Excellent meta-game proposition, but rights/provenance problems; an unauthorized Virtual Sysop III port is explicitly unacceptable. | 2026-09-04 | A legitimate, rights-clear port/port-path is established. |
| Holsham Traders | htsserver, nhtsclient | REJECTED | Aug-31 called it a "restoration candidate"; **Sep-3 hands-on check found it a dead 24-year pre-alpha** ("you cannot play the game, yet" — its own README). Newest wins. | 2026-09-03 | Project sees real revival activity. |
| Tic-Tac-Foe | moldandyeast/tic-tac-foe | REJECTED | Platform lock-in (Cloudflare Worker + Durable Object, self-host path unclear), prototype quality, disconnect/permadeath issues, weak persistence, crowd dependence. | 2026-08-31 | Self-host path clarified + maturity improves. |
| dmud | dustywusty/dmud | REJECTED | Hard MUD exclusion (its own description: "a small multiplayer text MUD"); also no license, dormant, sparse content. | 2026-08-31 | N/A — hard rule exclusion, not merely a maturity gate. |
| Nostrian Conquest | — | REJECTED | Specific rejection only — do not generalize into rejecting strategy/empire games broadly. | 2026-08-31 | Material new fact specific to this title. |
| Veloren | — | REJECTED | Out of scope — requires a graphical client. | 2026-08-31 | A genuine terminal path appears. |
| Blocks Beyond the Stars | — | REJECTED | Out of scope — no terminal path. | 2026-08-31 | A genuine terminal path appears. |
| SwampyMUD / Magic MUD | — | REJECTED | No compelling reason to foreground conventional MUDs; hard MUD exclusion also applies. | 2026-08-31 | N/A — hard rule exclusion. |
| Background MUD leads | Acolyte, Moral Decay, Rites of Passage, Insomnia, LuminariMUD | REJECTED | Background MUD leads only; hard MUD exclusion applies to all. | 2026-08-31 | N/A — hard rule exclusion. |
| TetriNET / Tetrinet | — | REJECTED | **Dedup note:** Aug-31 ledger already rejected this (ncurses client needs a 50-line display; `tetris-terminal` is the better modern lead) — by Sep-4 it had already drifted back in as "prior-history/dedup uncertain." This row exists specifically to stop that drift recurring. | 2026-08-31 | A build with a real 80x24-class display path appears. |
| TAP-42 | — | REJECTED | Technically elegant but content/world too thin; mutable state resets. | 2026-08-31 | World/content depth added + persistence fixed. |
| WHY roguelike | — | REJECTED | Bare prototype. | 2026-08-31 | Materially more complete build appears. |
| GalacticTerminal.com | — | REJECTED | Browser-based retro-terminal aesthetic; no demonstrated character-terminal route. | 2026-08-31 | A real character-terminal route is demonstrated. |
| Crawler | — | REJECTED | WIP with acknowledged gameplay/economy gaps. | 2026-08-31 | Gaps are closed in a real release. |
| DOOMQL | — | REJECTED | Single-player; awkward geometry/rendering; also DOOM-clone-adjacent. | 2026-08-31 | Material rework changes both findings. |
| Joined Hunt | — | REJECTED | Small prototype, weak service maturity. | 2026-08-31 | Service maturity materially improves. |
| ClawCity | — | REJECTED | Named in the Sep-4 Final Rejects list; no further detail recorded. | 2026-09-04 | Material new fact directly addressing why it was rejected (re-verify before reopening; reason detail not preserved). |
| Far Horizons | — | REJECTED | Matt said not fun. | 2026-09-04 | Gameplay itself changes materially, not merely code. |
| Mercator | — | REJECTED | Matt said not fun. | 2026-09-04 | Gameplay itself changes materially, not merely code. |
| Agent World Protocol | — | REJECTED | Named in the Sep-4 Final Rejects list; no further detail recorded. | 2026-09-04 | Material new fact (re-verify before reopening). |
| Epimethean | — | REJECTED | Named in the Sep-4 Final Rejects list; no further detail recorded. | 2026-09-04 | Material new fact (re-verify before reopening). |
| Epicinium | — | REJECTED | Named in the Sep-4 Final Rejects list; no further detail recorded. | 2026-09-04 | Material new fact (re-verify before reopening). |
| twclone | — | REJECTED | Named in the Sep-4 Final Rejects list; TradeWars-clone family. | 2026-09-04 | Material new fact (re-verify before reopening). |
| Galactic Warzone 2025 | — | REJECTED | Named in the Sep-4 Final Rejects list; no further detail recorded. | 2026-09-04 | Material new fact (re-verify before reopening). |
| TW 3002 AI | — | REJECTED | Named in the Sep-4 Final Rejects list; TradeWars-family. | 2026-09-04 | Material new fact (re-verify before reopening). |
| Trade Wars Frontier | — | REJECTED | Named in the Sep-4 Final Rejects list; TradeWars-family. | 2026-09-04 | Material new fact (re-verify before reopening). |
| TW2002/TWX/Mombot automation helpers | — | REJECTED | Classic TradeWars 2002 automation-helper family; named in the Sep-4 Final Rejects list. | 2026-09-04 | N/A — automation-helper category, not a distinct game. |
| njudge/Diplomacy judge | — | REJECTED | Named in the Sep-4 Final Rejects list; PBEM judge software, not a terminal Experience. | 2026-09-04 | Material new fact (re-verify before reopening). |
| Atlantis/Olympia family | — | REJECTED | Named in the Sep-4 Final Rejects list; PBEM strategy family. | 2026-09-04 | Material new fact (re-verify before reopening). |
| Wasteland PBEM | — | REJECTED | Named in the Sep-4 Final Rejects list; play-by-email, not terminal-native. | 2026-09-04 | A real terminal-native path appears. |
| newStars/FreeStars/Stars! clone family | — | REJECTED | Named in the Sep-4 Final Rejects list. | 2026-09-04 | Material new fact (re-verify before reopening). |
| OpenMafia | — | REJECTED | Named in the Sep-4 Final Rejects list; social-deduction family. | 2026-09-04 | Material new fact (re-verify before reopening). |
| Judge Dredd 2025 | — | REJECTED | Named in the Sep-4 Final Rejects list; likely IP/licensing concern given the property. | 2026-09-04 | Licensing clarified + material new fact. |
| Solar Imperium | — | REJECTED | Named in the Sep-4 Final Rejects list. | 2026-09-04 | Material new fact (re-verify before reopening). |
| Arrowbridge | — | REJECTED | Named in the Sep-4 Final Rejects list. | 2026-09-04 | Material new fact (re-verify before reopening). |
| Quest for Nora | — | REJECTED | Named in the Sep-4 Final Rejects list. | 2026-09-04 | Material new fact (re-verify before reopening). |
| Online Digdroid | — | REJECTED | Named in the Sep-4 Final Rejects list. | 2026-09-04 | Material new fact (re-verify before reopening). |
| Starship Galactica | — | REJECTED | Named in the Sep-4 Final Rejects list. | 2026-09-04 | Material new fact (re-verify before reopening). |
| The Arcadian Legends | — | REJECTED | Named in the Sep-4 Final Rejects list. | 2026-09-04 | Material new fact (re-verify before reopening). |
| Shut The Box 2026 | — | REJECTED | Named in the Sep-4 Final Rejects list; conventional dice-game filler. | 2026-09-04 | N/A — conventional-filler exclusion. |
| Dominion | — | REJECTED | Named in the Sep-4 Final Rejects list; likely conventional card-game filler/IP concern. | 2026-09-04 | Material new fact (re-verify before reopening). |
| NY2008 | — | REJECTED | Named in the Sep-4 Final Rejects list, "for Curated" specifically — may still suit Game Hall if ever revisited. | 2026-09-04 | Curated: material new fact. Game Hall was never assessed. |
| Time Port | — | REJECTED | Named in the Sep-4 Final Rejects list, "for Curated" specifically. | 2026-09-04 | Curated: material new fact. Game Hall was never assessed. |
| Eclectic Avenue | — | REJECTED | Named in the Sep-4 Final Rejects list, "for Curated" specifically. | 2026-09-04 | Curated: material new fact. Game Hall was never assessed. |
| crib / jack (cribbage-over-SSH) | cribbage.world | REJECTED | **NEW (2026-09-12, Content Hunt v2, Pass C — adjacent-software channel).** Real terminal cribbage client+server, live public server at `cribbage.world:22000`. Hard-excluded: conventional card game. | 2026-09-12 | N/A — hard rule exclusion (conventional card game), not a maturity gate. |
| bubble-games | christopher-kleine/bubble-games | REJECTED | **NEW (2026-09-12, Content Hunt v2, Pass A — Charm/Bubble Tea showcase channel).** SSH game hub built on Wish+Bubble Tea; currently ships exactly one game (TicTacToe) — conventional filler, too thin to be an Experience. | 2026-09-12 | The hub ships multiple substantive non-conventional games. |
| Coop Catacombs | AikonCWD/coop-catacombs | REJECTED | **NEW (2026-09-12, Content Hunt v2, Pass A — r/roguelikedev channel).** Genuinely interesting asynchronous-coop roguelike design (messages/traps/items left for other players' runs) — but it is a graphical Windows/Linux/Mac/browser game, not terminal-native; no ASCII/curses execution model evidenced. Fails the terminal-path requirement. | 2026-09-12 | A real terminal/curses build or mode is demonstrated. |
| spong | fvcalderan/spong | REJECTED | **NEW (2026-09-12, Content Hunt v2, Pass C — adjacent-software channel).** Real Python curses terminal pong, host/client. Thin tech-demo depth, no persistence/progression — a TUI toy, not an Experience. | 2026-09-12 | Materially more depth/content is added upstream. |

## Registry — NEVER_SURFACE

| Canonical | Aliases | Status | Reason | Last Seen | Reopen Only If |
|---|---|---|---|---|---|
| Terminal Space Program | TSP, jasonfen/terminal-space-program | NEVER_SURFACE | Explicitly logged twice as "repeatedly resurfaced; do not present again" (Aug-31 PASS language + Sep-4 explicit call-out); keeps reappearing via an un-logged disk clone. Suppress completely. | 2026-09-04 | Matt explicitly asks for it by name. |
| terminal-mmo | — | NEVER_SURFACE | Logged as "repeatedly resurfaced; final reject" (Aug-31 WATCH-CLOSELY language superseded by Sep-4's explicit final reject). Suppress completely. | 2026-09-04 | Matt explicitly asks for it by name. |

---

## Deliberately excluded (architecture/reference, not candidates)

Per instruction, pure architecture/design/engine references from the Aug-31
ledger are **not** given candidate rows unless a search could plausibly
mistake them for a playable Experience: `uterm`, `sshx`, `Concordia`,
`Guncho`, `Identical`, `Binary Breach`, `The Terminal` (design lesson only),
`TUI Craft`, `Terminal Games` (infra source), `LociTerm`, `game-sync-engine`,
`Arkyv`, `PLATO/Cyber1` (as a category). If a future search surfaces one of
these AS a game, add it as a real row then.

---

## Sources consulted

- `/root/L33TEST_Crossroads_Master_Continuity_2026-09-05.md` — newest explicit-verdict authority
- `/root/Crossroads_Experience_Prospecting_Ledger_2026-09-04.md`
- `/root/Crossroads_Experience_Prospecting_Ledger_2026-08-31.md` — supplied the ssHattrick/AsciiArena/Rebels-in-the-Sky corrections and the bulk of this pass's negative-memory expansion
- `/root/binktermphp/app/docs/checkpoints/CROSSROADS_FREEZE_2026-08-31.md`
- `/root/openglad-assay/CROSSROADS_NEXT_CANDIDATE_LEDGER_REVIEW.md` (2026-09-03 inventory/dedupe pass — predecessor report, predates ssHattrick/AsciiArena)
- Session memory (`crossroads-exp3-selection-ledger.md`, `rebels-in-the-sky-crossroads-exp3-fail.md`) — cross-checked only
- Circumstantial filesystem evidence: `sshattrick-core-0.1.0` etc. in the local cargo registry cache (dated 2026-08-24) — corroborates, does not replace, the Aug-31 ledger's written verdict
- `L33TEST_Reconstructed_Development_Action_Map_2026-09-05.md` — named as a possible source, **not found on disk**, not used
