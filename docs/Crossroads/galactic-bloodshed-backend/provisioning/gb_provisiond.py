#!/usr/bin/env python3
"""gb_provisiond.py -- narrow Galactic Bloodshed provisioning daemon.

Runs inside the gb-admin/provisioner container, which has (a) the `enrol`
binary and (b) the SAME persistent volume as the always-on gb-server
container -- and NOTHING else binkterm-app needs. Exists so binkterm-app
never needs Docker socket access, shell/exec access, or direct filesystem
access to the GB data directory merely to run one enrollment.

Listens on a Unix domain socket (default /run/gb-provisiond/gb-provisiond.sock)
reachable only by containers that have that specific directory bind-mounted --
not by anything else on the docker network, and not by anything on the host
network stack at all (a Unix socket has no IP/port). A shared-secret token is
still required as defense in depth (see AUTH_TOKEN_FILE below) in case that
directory is ever mounted more broadly than intended.

Wire protocol (deliberately minimal -- see the parent slice report "Narrow
protocol/contract" for why an interactive relay, not a single request/
response, is the right shape here):

  1. Client connects and sends exactly one line of JSON:
       {"token": "<shared secret>", "race_password": "...", "governor_password": "..."}
  2. The daemon spawns exactly one `enrol --db <fixed path>` (no caller-
     supplied path or arguments, ever -- eliminates injection surface by
     construction) and becomes a relay:
       - the deity/guest/normal prompt and both password prompts are
         answered by the daemon itself, from the request above -- NEVER
         shown to the client, exactly as gb_launcher.py did locally before
         this daemon existed.
       - every other prompt (racial type, home planet, accept-stats, home
         sector, compatibilities -- the genuine gameplay-defining choices,
         see the parent slice report section B) is relayed as raw bytes,
         unmodified, in both directions. The daemon does not interpret
         these; it only recognizes the three fixed identity-relevant
         prompts to intercept.
  3. On completion the daemon sends one final line -- a NUL byte followed by
     a JSON object and a newline, then closes the connection:
       \x00{"status": "ok", "playernum": N}\n
       \x00{"status": "error", "message": "..."}\n
     The NUL prefix is unambiguous framing: enrol's own prompt text can never
     contain one, so the client can relay every prior byte straight to the
     real caller's terminal and know that whatever follows a NUL is daemon
     control data, not game text, without needing a length-prefixed protocol.

Only ONE `enrol` process runs at a time (a global lock) -- deliberately
avoiding any question of concurrent SQLite writers racing each other; the
identity broker on the BinkTerm side already guarantees at most one
provisioning attempt per user, so serializing here costs nothing in practice
and removes a whole class of WAL-mode contention scenarios to reason about.
A connection that sends nothing for IDLE_TIMEOUT_SEC, or that never
completes within SESSION_TIMEOUT_SEC, is dropped and its `enrol` child is
killed -- so one stuck/abandoned caller can't wedge the service.

Never logs a race/governor password. Logs only: connection open/close,
enrol start/end and exit status, playernum on success, and errors.
"""
from __future__ import annotations

import asyncio
import json
import logging
import os
import signal
import sys
import time

SOCKET_PATH = os.environ.get("GB_PROVISIOND_SOCKET", "/run/gb-provisiond/gb-provisiond.sock")
ENROL_BIN = os.environ.get("GB_ENROL_BIN", "/usr/local/bin/enrol")
GB_DB_PATH = os.environ.get("GB_DB_PATH", "/var/lib/galactic-bloodshed/gb.db")
AUTH_TOKEN_FILE = os.environ.get("GB_PROVISIOND_TOKEN_FILE", "/run/secrets/galactic_bloodshed_provisiond_token")

IDLE_PROMPT_GAP_SEC = 0.2  # passthrough-prompt boundary heuristic -- ported from gb_launcher.py's original
IDLE_TIMEOUT_SEC = 30
SESSION_TIMEOUT_SEC = 15 * 60  # a human answering real prompts can take a while
MAX_HEADER_BYTES = 4096

logging.basicConfig(level=logging.INFO, format="%(asctime)s gb_provisiond %(levelname)s %(message)s")
log = logging.getLogger("gb_provisiond")

_enrol_lock = asyncio.Lock()


def _load_token() -> str:
    with open(AUTH_TOKEN_FILE, "r") as f:
        return f.read().strip()


async def _read_line(reader: asyncio.StreamReader, limit: int, timeout: float) -> bytes:
    return await asyncio.wait_for(reader.readuntil(b"\n"), timeout=timeout)


async def _relay_enrol(reader: asyncio.StreamReader, writer: asyncio.StreamWriter,
                        race_password: str, governor_password: str) -> tuple[bool, int | None, str, bytes]:
    """Returns (ok, playernum, detail-for-logging, final_text_for_client).
    final_text_for_client is enrol's own trailing success banner (only ever
    non-empty on success) -- the caller combines it with the NUL-prefixed
    result in ONE write, since the client can't otherwise tell "here is
    final informational text, no answer needed" apart from "a prompt is
    waiting for you" on a plain (non-NUL) passthrough chunk."""
    proc = await asyncio.create_subprocess_exec(
        ENROL_BIN, "--db", GB_DB_PATH,
        stdin=asyncio.subprocess.PIPE,
        stdout=asyncio.subprocess.PIPE,
        stderr=asyncio.subprocess.STDOUT,
    )

    async def kill() -> None:
        if proc.returncode is None:
            proc.kill()
            try:
                await asyncio.wait_for(proc.wait(), timeout=5)
            except asyncio.TimeoutError:
                pass

    buf = b""
    tail = ""
    deadline = time.monotonic() + SESSION_TIMEOUT_SEC

    try:
        while True:
            if time.monotonic() > deadline:
                await kill()
                return False, None, "session timed out", b""

            # Accumulate until a genuine idle gap (enrol has no structured
            # "ready for input" signal -- see gb_launcher.py's original
            # documented heuristic, ported here unchanged): a multi-line
            # prompt like the sector-choice list can arrive as several
            # separate reads, and treating the first partial chunk as a
            # complete prompt would desync the conversation.
            try:
                chunk = await asyncio.wait_for(proc.stdout.read(4096), timeout=IDLE_PROMPT_GAP_SEC)
            except asyncio.TimeoutError:
                chunk = None

            if chunk == b"":
                break  # enrol exited
            if chunk is not None:
                buf += chunk
                tail = (tail + chunk.decode(errors="replace"))[-2000:]

                if buf.endswith(b"Deity/Guest/Normal (d/g/n) ?"):
                    proc.stdin.write(b"n\n")
                    await proc.stdin.drain()
                    buf = b""
                    continue
                if buf.endswith(b"Enter the password for this race:"):
                    proc.stdin.write((race_password + "\n").encode())
                    await proc.stdin.drain()
                    buf = b""
                    continue
                if buf.endswith(b"Enter the password for this leader:"):
                    proc.stdin.write((governor_password + "\n").encode())
                    await proc.stdin.drain()
                    buf = b""
                    continue
                if b"You are player " in buf:
                    # Final informational text, not a prompt -- deliberately
                    # NOT written to the client here. It's needed-no-answer
                    # text, and the client can't tell "no answer needed" from
                    # "a prompt is waiting" on a plain passthrough chunk (its
                    # NUL-framing only distinguishes trailer-vs-passthrough).
                    # Left in `buf` and folded into the final NUL-prefixed
                    # trailer below instead, in the SAME write.
                    break
                continue  # more bytes may still be coming; keep accumulating

            # Idle gap with a non-empty, non-injected buffer: a genuine
            # passthrough prompt is now complete and awaiting a real line of
            # caller input. Relay it, then relay exactly one line of the
            # client's real answer back to enrol.
            if not buf:
                if proc.returncode is not None:
                    break
                continue
            writer.write(buf)
            await writer.drain()
            buf = b""
            try:
                answer = await asyncio.wait_for(reader.readuntil(b"\n"), timeout=IDLE_TIMEOUT_SEC)
            except asyncio.TimeoutError:
                await kill()
                return False, None, "caller went idle waiting for a prompt answer", b""
            except asyncio.IncompleteReadError:
                # The client (gb_launcher.py) closed its connection to us
                # mid-prompt -- a genuine disconnect, distinct from the
                # idle timeout above. Previously uncaught here (only
                # TimeoutError was), so it fell through to handle_client's
                # generic Exception handler with a less specific reason.
                await kill()
                return False, None, "connection closed while awaiting a prompt answer", b""
            proc.stdin.write(answer)
            await proc.stdin.drain()

        rc = await asyncio.wait_for(proc.wait(), timeout=5)
        if "You are player " in tail:
            try:
                playernum = int(tail[tail.find("You are player ") :].split()[3].rstrip("."))
            except (IndexError, ValueError):
                playernum = None
            return playernum is not None, playernum, tail, (buf if playernum is not None else b"")
        return False, None, f"enrol exited {rc} without a success line", b""
    except (BrokenPipeError, ConnectionResetError) as e:
        await kill()
        return False, None, f"connection lost: {e}", b""
    finally:
        await kill()


async def handle_client(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
    peer = writer.get_extra_info("peername") or writer.get_extra_info("sockname") or "unix-peer"
    log.info("connection from %s", peer)
    try:
        header_raw = await _read_line(reader, MAX_HEADER_BYTES, IDLE_TIMEOUT_SEC)
        if len(header_raw) > MAX_HEADER_BYTES:
            raise ValueError("header too large")
        header = json.loads(header_raw.decode())

        expected_token = _load_token()
        if not isinstance(header.get("token"), str) or header["token"] != expected_token:
            log.warning("rejected connection from %s: bad token", peer)
            writer.write(b"\x00" + (json.dumps({"status": "error", "message": "unauthorized"}) + "\n").encode())
            await writer.drain()
            return

        race_password = header["race_password"]
        governor_password = header["governor_password"]
        if not race_password or not governor_password or " " in race_password or " " in governor_password:
            raise ValueError("malformed credentials in request")

        if _enrol_lock.locked():
            log.info("queuing %s: another enrol is in flight", peer)
        async with _enrol_lock:
            log.info("starting enrol for %s", peer)
            ok, playernum, detail, final_text = await _relay_enrol(reader, writer, race_password, governor_password)

        if ok:
            log.info("enrol succeeded for %s: playernum=%s", peer, playernum)
            result = {"status": "ok", "playernum": playernum}
        else:
            log.warning("enrol failed for %s: %s", peer, detail)
            result = {"status": "error", "message": "enrollment did not complete"}
        # final_text (enrol's own success banner, if any) and the trailer go
        # in ONE write -- see _relay_enrol's docstring for why.
        writer.write(final_text + b"\x00" + (json.dumps(result) + "\n").encode())
        await writer.drain()
    except Exception as e:
        log.exception("session error from %s", peer)
        try:
            writer.write(b"\x00" + (json.dumps({"status": "error", "message": "internal error"}) + "\n").encode())
            await writer.drain()
        except Exception:
            pass
    finally:
        writer.close()
        try:
            await writer.wait_closed()
        except Exception:
            pass
        log.info("connection closed for %s", peer)


async def main() -> None:
    socket_dir = os.path.dirname(SOCKET_PATH)
    os.makedirs(socket_dir, exist_ok=True)
    if os.path.exists(SOCKET_PATH):
        os.remove(SOCKET_PATH)

    server = await asyncio.start_unix_server(handle_client, path=SOCKET_PATH)
    # 0600, owner-only (this process's own UID, from GB_SERVICE_UID / the
    # container's service user -- see runtime/Dockerfile.runtime): live
    # inspection of binkterm-app (see the parent slice report "Final
    # provisioning permission model") confirmed its NativeDoor multiplexing
    # bridge -- and everything it spawns, including the launcher that
    # connects here -- runs as root, which bypasses Unix file-permission
    # checks entirely. A narrow owner-only mode therefore still admits the
    # one legitimate caller while actually excluding every other non-root
    # process that might otherwise share this bind-mounted directory. The
    # shared-secret token check in handle_client remains the check that
    # doesn't depend on any of this.
    os.chmod(SOCKET_PATH, 0o600)
    log.info("listening on %s (enrol=%s db=%s)", SOCKET_PATH, ENROL_BIN, GB_DB_PATH)

    loop = asyncio.get_running_loop()
    stop = loop.create_future()
    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, lambda: stop.done() or stop.set_result(None))

    async with server:
        await stop
        log.info("shutting down")


if __name__ == "__main__":
    asyncio.run(main())
