# ascii-royale M3 proof adapter

This directory is the L33TEST-owned, administrator-only web integration seam
for the unmodified `chad/ascii-royale` client. It is not a production arena.

## Upstream pin and binary

The only approved upstream revision is:

```text
ac7d9771dfd788b278427db619e43989d4317029
```

Build from the canonical repository with the pinned lockfile:

```text
git clone https://github.com/chad/ascii-royale
git checkout --detach ac7d9771dfd788b278427db619e43989d4317029
test "$(git rev-parse HEAD)" = ac7d9771dfd788b278427db619e43989d4317029
cargo build --release --locked
sha256sum target/release/ascii-royale
```

The executable is an ignored runtime artifact, never a tracked repository
file. For M3 it is installed at:

```text
data/runtime/ascii-royale-m3/ac7d9771dfd788b278427db619e43989d4317029/ascii-royale
```

Record and compare its SHA-256 with the artifact produced by the verified
checkout before every proof. Never stage or commit the executable.

## Security and lifecycle boundary

The BinkTerm native adapter launches `launch-ascii-royale.sh` with only the
authenticated username and numeric user ID. The launcher derives a display
call sign, privately reads a fresh EndpointId from
`data/run/ascii-royale-m3/endpoint-id`, and uses `exec` so node-pty directly
owns the upstream client process.

The EndpointId must not appear in this manifest, BinkTerm data/API responses,
WebSocket metadata, terminal output, logs, Git, or retained evidence. Its only
permitted transient locations are the protected runtime file, launcher memory,
and the upstream join process argv.

The runtime directory and channel are root-owned with modes 0750 and 0640.
In the current M3 container the bridge runs as root and PHP-FPM workers run as
www-data, so PHP cannot read the channel. This is a deployment observation,
not a portable BinkTerm privilege contract, and must be revisited before
productionization.

The pinned client has no startup audio-disable option. M3 supplies an external
ALSA null-device configuration through `ALSA_CONFIG_PATH`; upstream source is
not changed.
