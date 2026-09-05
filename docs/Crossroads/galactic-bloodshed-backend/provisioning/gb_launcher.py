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

Two distinct mechanisms are used, chosen for what each subprocess actually
needs -- NOT a single one-size-fits-all pty scraper:

  1. `enrol` automation (plain pipes, no pty): enrol is a completely ordinary
     buffered stdin/stdout program (std::getline(std::cin, ...)) -- it never
     puts the terminal in raw mode and never needs one. We relay it through
     plain subprocess pipes. Three of its prompts are intercepted by EXACT
     string match against the literal prompts in gb/server/enrol.cc (not
     fuzzy/visual scraping -- these strings are the actual source, pinned to
     the GB revision this was built against): the deity/guest/normal prompt
     (always answered "n" -- a regular BinkTerm user is never silently made
     a GB deity or guest) and the two password prompts (answered with the
     opaque generated credential, never shown to the caller). Every OTHER
     prompt -- racial type, home planet, accept-generated-stats, home sector
     preference, sector compatibilities -- is a genuine race-design choice
     (see the parent slice report, section B) and is relayed live to/from the
     real caller: we print enrol's exact prompt text and read one real line
     of input from the caller, unmodified. Prompt boundaries for this
     passthrough case are detected by a short idle gap after unterminated
     output (enrol has no structured "ready for input" signal to key off of
     instead) -- a documented timing heuristic, not text-content guessing.

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
import struct
import subprocess
import sys
import termios
import time
from dataclasses import dataclass

APP_ROOT = os.environ.get("GB_APP_ROOT", "/root/binktermphp/app")
PHP_BIN = os.environ.get("GB_PHP_BIN", "php")
IDENTITY_CLI = os.path.join(APP_ROOT, "scripts/galactic_bloodshed/gb_identity_cli.php")

ENROL_BIN = os.environ["GB_ENROL_BIN"]
GB_DB_PATH = os.environ["GB_DB_PATH"]
CLIENT_PY = os.environ["GB_CLIENT_PY"]
GB_HOST = os.environ.get("GALACTICBLOODSHED_HOST", "127.0.0.1")
GB_PORT = os.environ.get("GALACTICBLOODSHED_PORT", "2010")

IDLE_PROMPT_GAP_SEC = 0.2  # passthrough-prompt boundary heuristic; see module docstring
POST_LOGIN_MARKER = b"APs:"  # only ever appears once genuinely in-game (client.py scope-prompt parse)
LOGIN_PASSWORD_PROMPT = b"Please enter your password:"


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


def run_enrol(race_password: str, governor_password: str, answer_source=None) -> EnrolResult:
    """Run `enrol`, auto-answering the 3 identity-relevant prompts and
    relaying every other prompt live to/from the real caller terminal (or, in
    tests, to/from `answer_source` -- a callable(prompt_text: str) -> str).
    """
    proc = subprocess.Popen(
        [ENROL_BIN, "--db", GB_DB_PATH],
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        bufsize=0,
    )
    fd = proc.stdout.fileno()
    os.set_blocking(fd, False)

    buf = b""
    tail = ""

    def safe_wait(timeout: float) -> None:
        """Never let a hung/misbehaving enrol leak a process: on timeout,
        kill it and reap unconditionally rather than propagating."""
        try:
            proc.wait(timeout=timeout)
        except subprocess.TimeoutExpired:
            proc.kill()
            proc.wait(timeout=5)

    def send(line: str) -> None:
        proc.stdin.write((line + "\n").encode())
        proc.stdin.flush()

    def prompt_caller(prompt_bytes: bytes) -> None:
        nonlocal tail
        text = prompt_bytes.decode(errors="replace")
        tail = (tail + text)[-2000:]
        if answer_source is not None:
            answer = answer_source(text)
        else:
            sys.stdout.write(text)
            sys.stdout.flush()
            answer = sys.stdin.readline().rstrip("\n")
        send(answer)

    last_byte_at = time.monotonic()
    while True:
        ready, _, _ = select.select([fd], [], [], IDLE_PROMPT_GAP_SEC)
        if ready:
            try:
                chunk = os.read(fd, 4096)
            except BlockingIOError:
                chunk = b""
            if chunk == b"":
                if proc.poll() is not None:
                    break
            else:
                buf += chunk
                last_byte_at = time.monotonic()

                # Security/identity-sensitive prompts: exact match, answered
                # immediately, never shown to the caller.
                if buf.endswith(b"Deity/Guest/Normal (d/g/n) ?"):
                    send("n")
                    buf = b""
                    continue
                if buf.endswith(b"Enter the password for this race:"):
                    send(race_password)
                    buf = b""
                    continue
                if buf.endswith(b"Enter the password for this leader:"):
                    send(governor_password)
                    buf = b""
                    continue

                success_at = buf.find(b"You are player ")
                if success_at != -1:
                    tail_text = buf.decode(errors="replace")
                    m = tail_text[tail_text.find("You are player ") :]
                    try:
                        playernum = int(m.split()[3].rstrip("."))
                    except (IndexError, ValueError):
                        playernum = None
                    if answer_source is None:
                        sys.stdout.write(tail_text)
                        sys.stdout.flush()
                    safe_wait(5)
                    return EnrolResult(ok=playernum is not None, playernum=playernum, transcript_tail=tail_text[-2000:])
                continue

        # Idle gap: if we're holding an unterminated, non-empty buffer that
        # isn't one of the known injected prompts, it's a genuine passthrough
        # prompt awaiting a real line of caller input.
        if buf and (time.monotonic() - last_byte_at) >= IDLE_PROMPT_GAP_SEC:
            prompt_caller(buf)
            buf = b""

        if proc.poll() is not None and not buf:
            break

    safe_wait(5)
    return EnrolResult(ok=False, playernum=None, transcript_tail=tail)


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
        result = run_enrol(identity["race_password"], identity["governor_password"])
        if not result.ok or result.playernum is None:
            fail_provisioning(user_id, identity["attempt_token"])
            print("\nRace creation did not complete. Please try again.", file=sys.stderr)
            return 1
        confirm_provisioned(user_id, identity["attempt_token"], result.playernum)
        race_password, governor_password = identity["race_password"], identity["governor_password"]
    else:
        race_password, governor_password = identity["race_password"], identity["governor_password"]

    return auto_login_and_attach(race_password, governor_password)


if __name__ == "__main__":
    sys.exit(main())
