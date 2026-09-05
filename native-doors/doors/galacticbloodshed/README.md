# Galactic Bloodshed NativeDoor

Wires the persistent L33TEST Galactic Bloodshed universe into Crossroads as a
NativeDoor, reachable identically from Telnet and the Web terminal (both go
through the same PTY-based multiplexing bridge).

```
galacticbloodshed/
├── nativedoor.json                 manifest (enabled=false until human acceptance)
├── launch-galactic-bloodshed.sh    entrypoint; sets production env, execs gb_launcher.py
├── gb_launcher.py                  L33TEST orchestrator (identity -> provisioner -> client)
├── gb-client.py                    OFFICIAL upstream entry point, vendored unmodified
└── gb_client/                      OFFICIAL upstream client package, vendored unmodified
                                     (kaladron/galactic-bloodshed pin d575334ec49a6bd387587acb968ba638d5cc98d1)
```

Not this door's concern -- lives elsewhere:
- The persistent server/provisioner containers, permanent universe, backups:
  `docs/Crossroads/galactic-bloodshed-backend/`.
- Identity/credential broker: `src/Crossroads/GalacticBloodshedIdentity.php` +
  `src/Crossroads/GalacticBloodshedSecretBox.php`.
- Provisioning daemon (`gb_provisiond.py`) runs in a *different* container
  (the admin/provisioner image) -- this door's `gb_launcher.py` only ever
  speaks to it over the Unix socket, never runs `enrol` itself.

Full history and design rationale: the Galactic Bloodshed slice reports in
this session, and `docs/Crossroads/galactic-bloodshed-backend/README.md` /
`PRODUCTION_SETUP.md`.
