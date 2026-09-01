# MultiZork backend — black-box regression harness

Proves that the credential-log redaction patches (`../patches/0002-*`,
`../patches/0003-*`) keep every credential out of `multizorkd`'s log
**without changing anything a player or the adapter can observe.**

## Running

```sh
./run-regression.sh          # ~1–2 min; needs docker + network for the pin clone
./run-regression.sh --keep   # leave the work dir (/tmp/multizork-regression.XXXX) for inspection
```

Everything happens in throwaway containers:

| step | where | image |
|---|---|---|
| clone pin + apply patches 1..3 | host `mktemp -d` | — |
| build `multizorkd.p1` / `.p2` / `.p3` | `docker run --rm` | `ubuntu:22.04` |
| drive the daemon + assert | `docker run --rm` | `binktermphp-binkterm-app:latest` |

The **running** `binkterm-app` container is never entered or modified. The
only thing taken from it is a *read-only* `docker cp` of the story file, whose
SHA-256 is checked against the value in `MultiZorkProduction.md`. Each harness
run gives the daemon its own empty working directory, so its hard-coded
`./multizork.sqlite3` state is fully isolated and discarded. The harness starts
the daemon with a known PID and, in `finally`, kills exactly that process
group — no name-pattern matching.

## The scenario (`harness.py`)

1. **alice** connects, presses enter at the access-code prompt, picks a name,
   chooses *new game* → scrape the **join code** from `join game 'XXXXXX'`.
   (`Created new instance` fires.)
2. **bob** connects, chooses *join*, types the join code → `Found it!`.
3. alice types `go` → both players are shown `access code: 'XXXXXX'` → scrape
   **alice's** and **bob's returning access codes**. (`Saving instance` fires.)
4. Ordinary in-game input: `open mailbox`, `read leaflet`, `go north`.
5. Both disconnect (`Destroying instance` fires). A fresh connection types
   **alice's access code** → `We found you!` (`Rehydrated archived instance`
   fires; valid reconnect).
6. A fresh connection types a bogus code `zzq000` → `I can't find a game`.

So one run exercises all four common instance-lifecycle log lines plus the
three credential-bearing input prompts.

## Assertions — `multizorkd.p3` with `--mode redacted` (12)

| # | assertion |
|---|---|
| 1 | valid returning access code still reconnects (`We found you!`) |
| 2 | invalid access code still rejected |
| 3 | ordinary gameplay input still logged verbatim |
| 4 | alice's returning access code appears **nowhere** in the daemon log |
| 5 | bob's returning access code appears **nowhere** in the daemon log |
| 6 | the invalid access code appears **nowhere** in the daemon log |
| 7 | **the expedition join code appears nowhere in the daemon log** |
| 8 | instance-lifecycle events are still logged (`Created` / `Destroying` seen) |
| 9 | every instance-lifecycle log line carries the `<redacted>` marker |
| 10 | ≥ 4 `New input … : <redacted>` markers were emitted (input branch fired) |
| 11 | ordinary gameplay input not over-redacted |
| 12 | non-secret log fields preserved (`Running with story`, `New connection from`) |

## Assertions — `multizorkd.p1` with `--mode verbatim` (5, the control)

Proves the scenario really drives the secrets to every log site, so a
`redacted` pass is meaningful:

| # | assertion |
|---|---|
| 1–3 | reconnect works / invalid rejected / ordinary input logged (behaviour baseline) |
| 4 | an unpatched daemon leaks a typed code into the input log |
| 5 | an unpatched daemon leaks the join code into an instance-lifecycle log line |

## Differential

`run-regression.sh` diffs the two client-visible transcripts (volatile 6-char
codes and the build-timestamp banner masked): they must be **identical** —
same prompts, same game text, same auth results — confirming `p3` changes
nothing observable.

Both modes also print an `[info]` list of the instance-lifecycle log lines the
run produced, so you can eyeball `'<redacted>'` vs the real hash.
