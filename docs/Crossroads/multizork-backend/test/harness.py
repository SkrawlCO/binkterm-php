#!/usr/bin/env python3
"""
Black-box regression harness for the multizorkd credential-input redaction
patch (docs/Crossroads/multizork-backend/patches/0002-*).

It drives a *disposable* multizorkd over its loopback TCP socket through a
full two-player create / join / go / play / reconnect scenario, captures the
daemon's own stdout+stderr log, and asserts on what that log does and does
not contain.

Run it once per binary:

    harness.py --bin ./multizorkd.p2 --story ./zork1-r88.dat \\
               --outdir ./out-p2 --mode redacted

  --mode redacted : the patched daemon. Secrets must never be logged as input.
  --mode verbatim : an UNpatched (loopback-only) daemon, used as the control /
                    differential baseline. Secrets are expected in the log;
                    this proves the scenario actually exercises the code paths.

Exit status is 0 only if every assertion for the chosen mode holds.
The scenario, the client-visible transcript and the daemon log are written
to --outdir for the differential check in run-regression.sh.
"""

import argparse
import json
import os
import re
import signal
import socket
import subprocess
import sys
import time

HOST = "::1"


class Client:
    """One line-oriented TCP connection to multizorkd, with an expect buffer."""

    def __init__(self, port, name):
        self.name = name
        self.sock = socket.create_connection((HOST, port), timeout=5)
        self.sock.settimeout(0.4)
        self.buf = ""
        self.transcript = []

    def drain(self, quiet=0.5, hard=6.0):
        """Read until the server has been silent for `quiet` seconds."""
        end = time.time() + hard
        last = time.time()
        while time.time() < end:
            try:
                chunk = self.sock.recv(65536)
            except socket.timeout:
                if time.time() - last >= quiet:
                    break
                continue
            if not chunk:
                break
            text = chunk.decode("latin-1")
            self.buf += text
            self.transcript.append(text)
            last = time.time()
        return self.buf

    def expect(self, needle, timeout=6.0):
        end = time.time() + timeout
        while time.time() < end:
            if needle in self.buf:
                return True
            self.drain(quiet=0.3, hard=1.5)
        raise AssertionError(
            f"[{self.name}] never saw {needle!r}\n--- buffer ---\n{self.buf[-800:]}"
        )

    def send(self, line):
        self.transcript.append(f"\n<<< {line!r}\n")
        self.sock.sendall(line.encode("latin-1") + b"\r\n")
        time.sleep(0.2)

    def clear(self):
        self.buf = ""

    def close(self):
        try:
            self.sock.close()
        except OSError:
            pass


def start_daemon(binary, story, workdir):
    """multizorkd hard-codes its sqlite path to ./multizork.sqlite3, so give it
    its own empty working directory -> fully isolated state."""
    os.makedirs(workdir, exist_ok=True)
    for stale in ("multizork.sqlite3",):
        p = os.path.join(workdir, stale)
        if os.path.exists(p):
            os.unlink(p)
    logf = open(os.path.join(workdir, "daemon.log"), "wb")
    # port 0 is rejected by the daemon; pick a high, likely-free port per pid
    port = 40000 + (os.getpid() % 20000)
    proc = subprocess.Popen(
        [os.path.abspath(binary), os.path.abspath(story), "--port", str(port)],
        cwd=workdir, stdout=logf, stderr=subprocess.STDOUT,
        preexec_fn=os.setsid,
    )
    # wait for the listener
    for _ in range(50):
        try:
            socket.create_connection((HOST, port), timeout=1).close()
            return proc, port, logf
        except OSError:
            if proc.poll() is not None:
                raise RuntimeError("daemon exited during startup")
            time.sleep(0.1)
    raise RuntimeError("daemon never opened its listener")


def onboard(port, screen_name):
    """Enter-name -> menu.  Returns a connected Client sitting at the
    'new game or join' menu."""
    c = Client(port, screen_name)
    c.drain()
    c.expect("Hello sailor!")
    c.clear()
    c.send("")                      # press enter at the access-code prompt
    c.expect("What's your name?")
    c.clear()
    c.send(screen_name)
    c.expect("1) start a new game")
    c.clear()
    return c


def run_scenario(binary, story, outdir):
    proc, port, logf = start_daemon(binary, story, outdir)
    codes = {}
    clients = []
    transcripts = {}
    try:
        # ---- player 1 creates a game -------------------------------------
        c1 = onboard(port, "alice"); clients.append(c1)
        c1.send("1")
        c1.expect("join game '")
        m = re.search(r"join game '([A-Za-z0-9]{4,16})'", c1.buf)
        assert m, f"could not scrape join code from:\n{c1.buf}"
        codes["join_code"] = m.group(1)
        c1.clear()

        # ---- player 2 joins with that code -----------------------------
        c2 = onboard(port, "bob"); clients.append(c2)
        c2.send("2")
        c2.expect("type it here")
        c2.clear()
        c2.send(codes["join_code"])          # <-- credential-bearing input
        c2.expect("Found it!")
        c2.clear()

        # ---- player 1 starts the game ---------------------------------
        c1.send("go")
        c1.expect("access code: '")
        c2.expect("access code: '")
        codes["alice_code"] = re.search(r"access code: '([A-Za-z0-9]{4,16})'", c1.buf).group(1)
        codes["bob_code"] = re.search(r"access code: '([A-Za-z0-9]{4,16})'", c2.buf).group(1)
        c1.clear(); c2.clear()

        # ---- ordinary gameplay input (must stay logged verbatim) -------
        for cmd in ("open mailbox", "read leaflet"):
            c1.send(cmd)
            c1.drain()
        c2.send("go north")
        c2.drain()

        # ---- disconnect, then a VALID returning reconnect --------------
        c1.close(); c2.close()
        clients.clear()
        time.sleep(1.0)

        c3 = Client(port, "alice-reconnect"); clients.append(c3)
        c3.drain(); c3.expect("Hello sailor!"); c3.clear()
        c3.send(codes["alice_code"])          # <-- credential-bearing input
        c3.expect("We found you!")
        reconnect_ok = "We found you!" in c3.buf
        c3.close(); clients.clear()

        # ---- an INVALID access code -----------------------------------
        bogus = "zzq000"
        c4 = Client(port, "bad-code"); clients.append(c4)
        c4.drain(); c4.expect("Hello sailor!"); c4.clear()
        c4.send(bogus)                        # <-- credential-bearing input
        c4.expect("I can't find a game")
        invalid_rejected = "I can't find a game" in c4.buf
        codes["bogus_code"] = bogus

        for c in (c1, c2, c3, c4):
            transcripts[c.name] = "".join(c.transcript)
        c4.close(); clients.clear()

    finally:
        for c in clients:
            c.close()
        try:
            os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
            proc.wait(timeout=5)
        except (ProcessLookupError, subprocess.TimeoutExpired):
            try:
                os.killpg(os.getpgid(proc.pid), signal.SIGKILL)
            except ProcessLookupError:
                pass
        logf.close()

    log = open(os.path.join(outdir, "daemon.log"), "r", errors="replace").read()

    # client-visible transcript, with the volatile 6-char codes masked so the
    # patched and control runs can be compared for identical game/auth behaviour
    def mask(s):
        for v in (codes.get("join_code"), codes.get("alice_code"),
                  codes.get("bob_code")):
            if v:
                s = s.replace(v, "<CODE>")
        return s

    with open(os.path.join(outdir, "transcript.txt"), "w") as fh:
        for name, t in transcripts.items():
            fh.write(f"----- {name} -----\n{mask(t)}\n")

    return codes, log, reconnect_ok, invalid_rejected


INPUT_LINE_RE = re.compile(r"^multizorkd: New input from socket \d+.*$", re.M)


def assertions(mode, codes, log, reconnect_ok, invalid_rejected):
    results = []

    def check(name, ok, detail=""):
        results.append((name, ok, detail))

    input_lines = INPUT_LINE_RE.findall(log)
    redacted_lines = [l for l in input_lines if l.rstrip().endswith(": <redacted>")]
    verbatim_lines = [l for l in input_lines if not l.rstrip().endswith(": <redacted>")]

    # --- behaviour that must hold no matter which binary this is ----------
    check("valid returning access code still reconnects", reconnect_ok)
    check("invalid access code still rejected", invalid_rejected)
    check("ordinary gameplay input still logged verbatim",
          any("'open mailbox'" in l for l in verbatim_lines)
          and any("'read leaflet'" in l for l in verbatim_lines)
          and any("'go north'" in l for l in verbatim_lines),
          detail="; ".join(l.split(": ", 1)[1] for l in verbatim_lines))

    secrets = {
        "returning access code (alice)": codes["alice_code"],
        "returning access code (bob)": codes["bob_code"],
        "invalid access code": codes["bogus_code"],
        "expedition join code": codes["join_code"],
    }

    log_lines = log.splitlines()
    lifecycle_markers = ("Created new instance", "Saving instance",
                         "Destroying instance", "Rehydrated archived instance")
    lifecycle_lines = [l for l in log_lines if any(m in l for m in lifecycle_markers)]

    if mode == "redacted":
        # (1) NO credential value appears ANYWHERE in the captured daemon log -
        #     not in a 'New input' line, not in an instance-lifecycle line, not
        #     anywhere.  Covers returning access codes AND the join code.
        for label, val in secrets.items():
            leaked = [l for l in log_lines if val in l]
            check(f"{label} never appears anywhere in the daemon log",
                  not leaked, detail="\n".join(leaked))

        # (2) the lifecycle events themselves are still logged (we did not just
        #     delete the diagnostics) - they now say '<redacted>'.
        check("instance-lifecycle events still logged",
              any("Created new instance" in l for l in lifecycle_lines)
              and any("Destroying instance" in l for l in lifecycle_lines),
              detail="\n".join(lifecycle_lines))
        check("instance-lifecycle lines carry the <redacted> marker",
              lifecycle_lines and all("<redacted>" in l for l in lifecycle_lines),
              detail="\n".join(lifecycle_lines))

        # (3) the input-line redaction branch fired for credential prompts
        check("redaction marker present for credential prompts",
              len(redacted_lines) >= 4,
              detail=f"{len(redacted_lines)} '<redacted>' input lines")

        # (4) ordinary, non-secret logging is untouched
        check("ordinary gameplay input still logged verbatim (not over-redacted)",
              any("'open mailbox'" in l for l in verbatim_lines)
              and any("'go north'" in l for l in verbatim_lines))
        check("non-secret log fields preserved (player counts, sockets, story name)",
              any("Running with story" in l for l in log_lines)
              and any("New connection from" in l for l in log_lines))

    else:  # verbatim / control  (an unpatched-for-this-change daemon)
        # prove the scenario really drives the secrets to every log site we
        # care about - otherwise a 'redacted' pass would be meaningless.
        check("control: a typed code reaches the input log",
              any(codes["join_code"] in l for l in input_lines)
              or any(codes["bogus_code"] in l for l in input_lines))
        check("control: the join code reaches an instance-lifecycle log line",
              any(codes["join_code"] in l for l in lifecycle_lines),
              detail="\n".join(lifecycle_lines))

    return results


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--bin", required=True)
    ap.add_argument("--story", required=True)
    ap.add_argument("--outdir", required=True)
    ap.add_argument("--mode", choices=("redacted", "verbatim"), required=True)
    args = ap.parse_args()

    os.makedirs(args.outdir, exist_ok=True)
    codes, log, reconnect_ok, invalid_rejected = run_scenario(
        args.bin, args.story, args.outdir)

    with open(os.path.join(args.outdir, "codes.json"), "w") as fh:
        json.dump(codes, fh, indent=2)

    results = assertions(args.mode, codes, log, reconnect_ok, invalid_rejected)

    print(f"\n=== {args.mode} :: {args.bin} ===")
    failed = 0
    for name, ok, detail in results:
        print(f"  [{'PASS' if ok else 'FAIL'}] {name}")
        if not ok and detail:
            for line in detail.splitlines():
                print(f"         {line}")
        failed += (not ok)

    # informational: show the instance-lifecycle log lines this run produced
    lifecycle = [l.strip() for l in log.splitlines()
                 if any(m in l for m in ("Created new instance", "Saving instance",
                                         "Destroying instance",
                                         "Rehydrated archived instance"))]
    if lifecycle:
        print("  [info] instance-lifecycle log lines produced this run:")
        for l in sorted(set(lifecycle)):
            print(f"         {l}")

    print(f"\n{len(results) - failed}/{len(results)} assertions passed")
    sys.exit(1 if failed else 0)


if __name__ == "__main__":
    main()
