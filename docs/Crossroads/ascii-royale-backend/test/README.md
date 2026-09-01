# ascii-royale backend — black-box regression harness

Proves the managed-arena wrapper (`../runtime/ascii-royale-arena.sh`) honours
its security + lifecycle contract, **against the exact committed script**,
without a real `ascii-royale` binary, without network, and without touching
the production `/var/lib/ascii-royale` or the running arena.

## Running

```sh
./run-regression.sh          # ~2 min; needs docker
```

Everything happens in one disposable `binktermphp-binkterm-app` container
(used only as the runtime ABI — `bash`, coreutils, `util-linux`/`setpriv`).
Each case gets its own throwaway `ARENA_ROOT` under `/tmp` and a throwaway
`artest` service account. `test/fake-serve.sh` stands in for `ascii-royale
serve`: it honours `--ticket-file`, prints the upstream `[arena] ticket: …`
line, and runs until `SIGTERM`. Its scenario (`normal` / `no_ticket` /
`crash`) is chosen by a `mode` file the harness seeds next to the ticket path
(the wrapper launches the arena with `env -i`, so it can't be an env var).

## Assertions (30)

**[1] normal start**
| what |
|---|
| endpoint channel is a regular, non-symlink file |
| channel is `root:root` `0640`; its directory is `root:root` `0750`, non-symlink |
| channel ≤ 1024 bytes |
| body: `version=1`, correct `pinned_sha`, numeric `updated_unix`, `host_generation` matches `^[A-Za-z0-9._-]{1,64}$`, `endpoint_id` matches `^[0-9a-f]{64}$` |
| the arena child ran as a **non-root** uid |
| the wrapper process itself is root |
| the EndpointId appears in **none** of the wrapper's own `ascii-royale-arena:` log lines |
| the wrapper tightens the supervisord log dir to `0750` and a world-readable `arena.out.log` to `0640`, group = the service account |
| heartbeat: `updated_unix` and mtime both advance |

**[2] stop + restart**
| what |
|---|
| `SIGTERM` removes `endpoint-id` |
| no `.endpoint-id.*` temp files remain |
| a restart republishes a valid channel |
| the restart's `host_generation` differs from the previous one |

**[3] no ticket → startup timeout**
| what |
|---|
| wrapper exits non-zero |
| the channel was **never** created (polled throughout) |
| the timeout reason is logged |

**[4] arena crash**
| what |
|---|
| the channel is published while the arena is up |
| when the arena exits, the wrapper exits non-zero (→ supervisor autorestart) |
| the channel is removed |

**[5] binary SHA-256 mismatch**
| what |
|---|
| the wrapper refuses to start (non-zero) |
| the channel is never created |
| the mismatch is logged |

## What is NOT covered here

A real end-to-end smoke against the pinned `ascii-royale` binary (iroh
reachability, a real 64-hex ticket, the committed M3 launcher `join`ing it)
needs network + the retained binary and is the **Phase B live-deployment
validation** in [`../../AsciiRoyaleProduction.md`](../../AsciiRoyaleProduction.md),
not this hermetic harness.
