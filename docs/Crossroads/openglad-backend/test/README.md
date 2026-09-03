# OpenGlad relay — black-box regression harness

Proves the tracked relay runtime
(`scripts/openglad/openglad-relay-runtime.cjs`) honours its wire contract, its
authorization boundary, and its limit table — **against the exact committed
script**, with no BinkTerm, no database, and no network.

## Running

```sh
./run-regression.sh            # ~90 s; needs docker
# or directly on any node >= 18:
node relay-contract.mjs
```

`run-regression.sh` runs it inside a disposable `binktermphp-binkterm-app`
container with `--network none` (the container is only the node 20 ABI). The
fake WebDoor-session authority is in-process in `relay-contract.mjs`
(cookie `authok=1` → authorized; `notauthorized=1` → 404; anything else → 401),
so the relay's real `authorize()` path is exercised without BinkTerm.

## Assertions (33)

**Transport / lifecycle plumbing**
| what |
|---|
| `GET /healthz` is 200 and **ungated** |
| `OPTIONS /api/*` preflight → 204 with `Access-Control-Allow-Origin: *` |
| `GET /healthz` reachable before any auth |

**Authorization boundary — both sides**
| what |
|---|
| `POST /api/create` with **no cookie** → 401 |
| `POST /api/create` authenticated but **not OpenGlad-authorized** (authority 404) → 401 |
| `POST /api/create` with a **stale/invalid session** (authority 401) → 401 |
| `WS /api/room/<code>` upgrade with **no cookie** → HTTP 401, socket closed |
| every rejected attempt above leaves **zero** rooms / peers (checked before & after) |
| a caller hitting `127.0.0.1:<port>` directly is gated identically — the check is in the relay, not a proxy (the harness always connects straight to the listener) |
| an **authorized** caller (`authok=1`) creates a room: 200, `code`/`room_code` = `GLAD-[A-Z0-9]{4}`, 32-hex `owner_token` |
| a positive authority result is **cached** briefly (6 gated requests → 1 authority hit) |

**Wire contract**
| what |
|---|
| join an unknown room → HTTP 404; a malformed code → HTTP 400 |
| a room is **hidden from `GET /api/rooms`** until a peer connects |
| owner (correct `owner_token`) gets peer id 1 and `{"type":"joined","peer_id":1,"host":1}` then `peer_list [1]` |
| a guest gets peer id 2, `peer_list [1,2]`; the owner is told `{"type":"peer_joined","peer_id":2}` |
| `GET /api/rooms` then lists the room with `player_count 2`, `host_name`, `campaign_hash` |
| a `[0x03]` broadcast from peer 2 reaches the owner as `[0x02][2][body]` |
| a `[0x01][target][body]` targeted frame reaches only that peer |

**Multi-room isolation**
| what |
|---|
| a second room A/B pair is created and joined |
| a flood of broadcast frames in room A reaches **no** peer of room B |
| room B's own internal relay keeps working during the room-A flood |

**Limits**
| what |
|---|
| 15 guests + 1 owner accepted; the 17th peer → HTTP 409 |
| per-IP create budget (5, harness override) exhausts → HTTP 429 |
| a 130 KiB binary frame → WS close **1009** |

**Room lifecycle alarms** (TTL / sweep overridden small for the test)
| what |
|---|
| owner alone leaves → room stays **reconnectable** within the empty-room TTL |
| after the TTL the room is **deleted** (rejoin → 404) |
| owner leaves **with a guest present** → the guest gets `{"type":"peer_left","peer_id":1}` then WS close **1001**, and the room is removed |
| an owner **reconnect** supersedes the old owner socket (old socket closed **1012**), the new socket is peer id 1 |
| a room whose owner never connects is not discoverable |

## What is NOT covered here

Real gameplay convergence through the deployed proxy chain against the pinned
WASM client, and the live `/api/webdoor/session` authority, are the **live
acceptance** in [`../../OpenGladProduction.md`](../../OpenGladProduction.md),
not this hermetic harness.
