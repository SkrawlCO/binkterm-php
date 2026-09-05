#!/usr/bin/env python3
"""gb_launcher.py -- L33TEST-owned Galactic Bloodshed NativeDoor launcher.

Staged integration tooling, NOT yet wired into a live nativedoor.json entry
(see ../../galactic-bloodshed-backend/README.md and the parent slice report
for why). Run manually or from tests for now.

Reads the authenticated caller's identity from the standard NativeDoor
environment contract (DOOR_USER_NUMBER, DOOR_USER_NAME -- see
docs/NativeDoors.md "Environment Variables"), asks BinktermPHP's
GalacticBloodshedIdentity broker (via gb_identity_cli.php) whether that user
already has a GB race, provisions one through the real `enrol` binary if not,
then auto-logs the caller into the real upstream Python/curses client and
hands over interactive control.

Two distinct mechanisms are used, chosen for what each remote endpoint
actually needs -- NOT a single one-size-fits-all pty scraper:

  1. Enrollment: NOT run locally. This process has no Docker socket access,
     no `enrol` binary, and no direct access to the GB data directory --
     see gb_provisiond.py (the narrow provisioning daemon) and the parent
     slice report "Narrow provisioning service" for why. Instead this
     connects to that daemon over a Unix socket and becomes a byte relay
     between it and the real caller's terminal: whatever the daemon sends is
     printed for the caller (that's enrol's own prompt text for the
     genuine race-design choices -- racial type, home planet,
     accept-generated-stats, home sector, compatibilities, see the parent
     slice report section B), and whatever the caller types is sent back.
     The daemon -- not this process -- is what auto-answers the
     deity/guest/normal prompt and the two password prompts, invisibly; this
     process never even sees those prompts or the credential go over its own
     terminal-facing side.

  1b. `run_enrol_via_provisioner()`'s `answer_source` parameter exists purely
     for tests that want to script the passthrough answers instead of
     reading a real terminal.

  2. Auto-login (a real pty): the upstream client is curses-based and
     requires one. A single, protocol-state-gated injection is used instead
     of scraping visible screen content: inner-pty output is BUFFERED (never
     forwarded to the caller) from the moment the client is spawned; the
     credential line is written the instant the buffered stream shows the
     server's fixed login banner ("Please enter your password:", sent by
     auth.cc welcome_user() before anything else); relaying to the caller's
     real terminal only begins once the buffered stream shows a definitive
     post-login marker. The credential is therefore never present in
     anything the caller's terminal ever displays, regardless of how the
     client's own curses UI would otherwise have echoed it.

Nothing here ever logs, prints, or passes as a subprocess argv the decrypted
race/governor password -- it lives only in local Python variables, is sent
directly to a subprocess's stdin pipe or pty fd, and is not retained after
the launch completes.
"""
from __future__ import annotations

import fcntl
import json
import os
import pty
import select
import signal
import socket
import struct
import subprocess
import sys
import termios
import time
from dataclasses import dataclass

APP_ROOT = os.environ.get("GB_APP_ROOT", "/root/binktermphp/app")
PHP_BIN = os.environ.get("GB_PHP_BIN", "php")
IDENTITY_CLI = os.path.join(APP_ROOT, "scripts/galactic_bloodshed/gb_identity_cli.php")

PROVISIOND_SOCKET = os.environ.get("GB_PROVISIOND_SOCKET", "/run/gb-provisiond/gb-provisiond.sock")
PROVISIOND_TOKEN_FILE = os.environ.get("GB_PROVISIOND_TOKEN_FILE", "/run/secrets/galactic_bloodshed_provisiond_token")
CLIENT_PY = os.environ["GB_CLIENT_PY"]
GB_HOST = os.environ.get("GALACTICBLOODSHED_HOST", "127.0.0.1")
GB_PORT = os.environ.get("GALACTICBLOODSHED_PORT", "2010")

POST_LOGIN_MARKER = b"APs:"  # only ever appears once genuinely in-game (client.py scope-prompt parse)
LOGIN_PASSWORD_PROMPT = b"Please enter your password:"
MAX_ENROL_ATTEMPTS = 5  # bounds the same-session retry loop in main() -- see its comment


class ProvisioningInProgress(Exception):
    pass


class GbLauncherError(Exception):
    pass


# --------------------------------------------------------------------- identity


def call_identity_cli(*args: str) -> dict:
    proc = subprocess.run(
        [PHP_BIN, IDENTITY_CLI, *args],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )
    try:
        result = json.loads(proc.stdout.strip() or "{}")
    except json.JSONDecodeError as e:
        raise GbLauncherError(f"gb_identity_cli.php produced non-JSON output: {proc.stdout!r}") from e

    if proc.returncode == 2:
        raise ProvisioningInProgress(result.get("message", "provisioning in progress"))
    if proc.returncode != 0:
        raise GbLauncherError(result.get("message", f"gb_identity_cli.php exit {proc.returncode}"))

    return result


def resolve_identity(binkterm_user_id: int) -> dict:
    return call_identity_cli("resolve", str(binkterm_user_id))


def confirm_provisioned(binkterm_user_id: int, token: str, playernum: int) -> None:
    call_identity_cli("confirm", str(binkterm_user_id), token, str(playernum))


def fail_provisioning(binkterm_user_id: int, token: str) -> None:
    call_identity_cli("fail", str(binkterm_user_id), token)


# ----------------------------------------------------------------- enrol phase


@dataclass
class EnrolResult:
    ok: bool
    playernum: int | None
    transcript_tail: str  # for diagnostics on failure; never contains a password


def run_enrol_via_provisioner(race_password: str, governor_password: str, answer_source=None) -> EnrolResult:
    """Connect to gb_provisiond.py over its Unix socket and relay the
    enrollment conversation to/from the real caller terminal (or, in tests,
    to/from `answer_source` -- a callable(prompt_text: str) -> str). See the
    module docstring and the parent slice report "Narrow provisioning
    service" for the protocol and why this process never runs `enrol`
    itself.
    """
    with open(PROVISIOND_TOKEN_FILE, "r") as f:
        token = f.read().strip()

    sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    sock.connect(PROVISIOND_SOCKET)
    sockfile = sock.makefile("rwb", buffering=0)

    header = json.dumps({
        "token": token,
        "race_password": race_password,
        "governor_password": governor_password,
    })
    sockfile.write((header + "\n").encode())

    tail = ""

    try:
        while True:
            try:
                chunk = sock.recv(65536)
            except (BrokenPipeError, ConnectionResetError, OSError) as e:
                # The daemon dropped us (most commonly its own
                # IDLE_TIMEOUT_SEC elapsing on a prior turn -- see below);
                # recoverable, not a crash -- main()'s retry loop treats this
                # exactly like any other failed attempt.
                return EnrolResult(ok=False, playernum=None, transcript_tail=tail + f"\n[connection lost: {e}]")
            if chunk == b"":
                return EnrolResult(ok=False, playernum=None, transcript_tail=tail + "\n[connection closed unexpectedly]")

            nul_at = chunk.find(b"\x00")
            if nul_at != -1:
                # Everything before the NUL is final passthrough text (e.g.
                # enrol's own "You are player N" line); relay it, then the
                # NUL marks the start of the daemon's JSON trailer.
                if nul_at > 0 and answer_source is None:
                    sys.stdout.write(chunk[:nul_at].decode(errors="replace"))
                    sys.stdout.flush()
                trailer = chunk[nul_at + 1 :]
                while not trailer.endswith(b"\n"):
                    try:
                        more = sock.recv(4096)
                    except (BrokenPipeError, ConnectionResetError, OSError):
                        break
                    if more == b"":
                        break
                    trailer += more
                try:
                    result = json.loads(trailer.decode())
                except json.JSONDecodeError:
                    return EnrolResult(ok=False, playernum=None, transcript_tail=tail + "\n[malformed daemon trailer]")
                ok = result.get("status") == "ok" and isinstance(result.get("playernum"), int)
                return EnrolResult(
                    ok=ok,
                    playernum=result.get("playernum") if ok else None,
                    transcript_tail=tail if ok else (tail + "\n" + str(result.get("message", "unknown error"))),
                )

            # No NUL: the daemon only ever sends a chunk like this once it has
            # already confirmed (via its own idle-gap accumulation) that this
            # is one complete, genuine passthrough prompt -- see
            # gb_provisiond.py's _relay_enrol(). This process just displays it
            # and relays back exactly one real line of caller input.
            text = chunk.decode(errors="replace")
            tail = (tail + text)[-2000:]
            if answer_source is not None:
                answer = answer_source(text)
            else:
                sys.stdout.write(text)
                sys.stdout.flush()
                answer = sys.stdin.readline().rstrip("\n")
            try:
                sockfile.write((answer + "\n").encode())
            except (BrokenPipeError, ConnectionResetError, OSError) as e:
                # The daemon gave up on us while we were blocked in
                # sys.stdin.readline() waiting for the caller's real
                # keystroke -- most commonly its own IDLE_TIMEOUT_SEC (30s)
                # elapsing, which a slow-typing human on a genuine prompt can
                # hit just as easily as a rapid blank-Enter retry. This used
                # to be an uncaught BrokenPipeError that crashed the whole
                # NativeDoor session; recoverable instead, same as any other
                # failed attempt -- main()'s retry loop reclaims a fresh
                # identity and starts over in the SAME session.
                return EnrolResult(ok=False, playernum=None, transcript_tail=tail + f"\n[connection lost: {e}]")
    finally:
        try:
            sockfile.close()
        finally:
            sock.close()


# --------------------------------------------------------------- login phase


def _get_winsize(fd: int) -> tuple[int, int]:
    try:
        rows, cols, _, _ = struct.unpack("HHHH", fcntl.ioctl(fd, termios.TIOCGWINSZ, b"\0" * 8))
        return rows or 24, cols or 80
    except OSError:
        return 24, 80


def _set_winsize(fd: int, rows: int, cols: int) -> None:
    fcntl.ioctl(fd, termios.TIOCSWINSZ, struct.pack("HHHH", rows, cols, 0, 0))


def auto_login_and_attach(race_password: str, governor_password: str) -> int:
    """Spawn the real upstream client under an inner pty, inject the
    credential the moment the login prompt appears (buffered/invisible to the
    caller), then relay bidirectionally until the client exits. Returns the
    client's exit status. Must be called with our own stdin/stdout already a
    real pty (the NativeDoor bridge's contract).

    Every exit path -- clean client exit, EOF/error on either fd, the
    caller's own stdin closing, or an unexpected exception here -- reaps or
    force-terminates the child before returning/re-raising, so this never
    leaves an orphaned gb-client.py process or a zombie behind.
    """
    rows, cols = _get_winsize(sys.stdin.fileno())
    old_termios = termios.tcgetattr(sys.stdin.fileno())

    pid, master_fd = pty.fork()
    if pid == 0:
        os.execvpe(sys.executable, [sys.executable, CLIENT_PY, GB_HOST, str(GB_PORT)], os.environ)
        os._exit(127)

    tty_was_raw = False
    prev_winch_handler = signal.getsignal(signal.SIGWINCH)

    def reap_nonblocking() -> int | None:
        try:
            done, status = os.waitpid(pid, os.WNOHANG)
        except ChildProcessError:
            return 0  # already reaped elsewhere; treat as gone
        return os.waitstatus_to_exitcode(status) if done == pid else None

    def ensure_child_gone() -> int:
        """Escalate SIGTERM -> SIGKILL until the child is confirmed reaped."""
        code = reap_nonblocking()
        for sig, grace in ((signal.SIGTERM, 2.0), (signal.SIGKILL, 2.0)):
            if code is not None:
                return code
            try:
                os.kill(pid, sig)
            except ProcessLookupError:
                return 0
            deadline = time.monotonic() + grace
            while time.monotonic() < deadline:
                code = reap_nonblocking()
                if code is not None:
                    return code
                time.sleep(0.05)
        try:  # last resort: blocking reap
            _, status = os.waitpid(pid, 0)
            return os.waitstatus_to_exitcode(status)
        except ChildProcessError:
            return 0

    try:
        _set_winsize(master_fd, rows, cols)
        try:
            import tty

            tty.setraw(sys.stdin.fileno())
            tty_was_raw = True
        except Exception:
            pass

        def forward_resize(_signum, _frame):
            r, c = _get_winsize(sys.stdin.fileno())
            _set_winsize(master_fd, r, c)
            try:
                os.kill(pid, signal.SIGWINCH)
            except ProcessLookupError:
                pass

        signal.signal(signal.SIGWINCH, forward_resize)

        suppressed = True
        injected = False
        buf = b""
        credential_line = f"{race_password} {governor_password}\r\n".encode()

        while True:
            try:
                ready, _, _ = select.select([master_fd, sys.stdin.fileno()], [], [], 1.0)
            except InterruptedError:
                continue

            if master_fd in ready:
                try:
                    data = os.read(master_fd, 65536)
                except OSError:
                    data = b""
                if not data:
                    return ensure_child_gone()

                if suppressed:
                    buf += data
                    if not injected and LOGIN_PASSWORD_PROMPT in buf:
                        os.write(master_fd, credential_line)
                        injected = True
                        buf = b""  # the prompt itself is consumed; nothing shown yet
                    if injected and POST_LOGIN_MARKER in buf:
                        suppressed = False
                        # Drop everything buffered during the suppressed window
                        # (which may contain an on-screen echo of the
                        # credential from the client's own input-line
                        # rendering) and let the client's next natural
                        # redraw -- nudged here -- repaint the real screen.
                        buf = b""
                        try:
                            os.kill(pid, signal.SIGWINCH)
                        except ProcessLookupError:
                            pass
                else:
                    os.write(sys.stdout.fileno(), data)

            if sys.stdin.fileno() in ready:
                data = os.read(sys.stdin.fileno(), 4096)
                if not data:
                    return ensure_child_gone()
                os.write(master_fd, data)

            code = reap_nonblocking()
            if code is not None:
                return code
    finally:
        signal.signal(signal.SIGWINCH, prev_winch_handler)
        if tty_was_raw:
            termios.tcsetattr(sys.stdin.fileno(), termios.TCSADRAIN, old_termios)
        ensure_child_gone()  # belt-and-suspenders: covers exceptions raised above
        try:
            os.close(master_fd)
        except OSError:
            pass


# --------------------------------------------------------------------- main


def main() -> int:
    user_id_raw = os.environ.get("DOOR_USER_NUMBER")
    if not user_id_raw or not user_id_raw.isdigit():
        print("gb_launcher: DOOR_USER_NUMBER missing from environment -- not launched as a NativeDoor?", file=sys.stderr)
        return 1
    user_id = int(user_id_raw)

    try:
        identity = resolve_identity(user_id)
    except ProvisioningInProgress:
        print("Your Galactic Bloodshed race is already being set up elsewhere -- try again in a minute.")
        return 0
    except GbLauncherError as e:
        print(f"Galactic Bloodshed identity lookup failed: {e}", file=sys.stderr)
        return 1

    if identity["status"] == "needs_provisioning":
        print("Setting up your Galactic Bloodshed race for the first time...\n")
        for attempt in range(1, MAX_ENROL_ATTEMPTS + 1):
            result = run_enrol_via_provisioner(identity["race_password"], identity["governor_password"])
            if result.ok and result.playernum is not None:
                confirm_provisioned(user_id, identity["attempt_token"], result.playernum)
                break

            # enrol exited on its own before finishing -- most commonly
            # upstream's own hard-exit on an unparseable answer to a numeric
            # prompt (gb/server/enrol.cc's scn::scan<int> calls have no
            # reprompt/default of their own: a blank Enter, or anything that
            # doesn't parse as a number, makes enrol itself return non-zero
            # immediately). That is upstream's real behavior, not a relay
            # bug -- preserved, not patched. What IS ours to fix: this used
            # to be treated as fatal to the whole NativeDoor session, which
            # ejected the caller to the lobby over a single mistyped
            # keystroke. Recoverable instead: discard the failed attempt's
            # credentials (fail_provisioning already does this) and restart
            # enrol from the top with a fresh claim, in the SAME session.
            fail_provisioning(user_id, identity["attempt_token"])

            if attempt == MAX_ENROL_ATTEMPTS:
                print(
                    "\nRace creation did not complete after several attempts. Please try again later.",
                    file=sys.stderr,
                )
                return 1

            print(
                "\nThat didn't go through -- every prompt needs a real answer "
                "(pressing Enter with nothing typed isn't accepted there). "
                "Let's start over.\n"
            )
            try:
                identity = resolve_identity(user_id)
            except ProvisioningInProgress:
                print("Your Galactic Bloodshed race is already being set up elsewhere -- try again in a minute.")
                return 0
            except GbLauncherError as e:
                print(f"Galactic Bloodshed identity lookup failed: {e}", file=sys.stderr)
                return 1
            if identity["status"] != "needs_provisioning":
                break  # reconciled to already-provisioned; fall through to auto-login

    race_password, governor_password = identity["race_password"], identity["governor_password"]
    return auto_login_and_attach(race_password, governor_password)


if __name__ == "__main__":
    sys.exit(main())
