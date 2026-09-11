#!/usr/bin/env python3
"""Local CLI proof only. No BBS connection, dependency installation or saved game."""
import errno
import fcntl
import json
import os
from pathlib import Path
import pty
import select
import struct
import subprocess
import sys
import termios
import time

output = Path(sys.argv[1])
output.mkdir(parents=True, exist_ok=True)
master, slave = pty.openpty()
fcntl.ioctl(slave, termios.TIOCSWINSZ, struct.pack('HHHH', 24, 80, 0, 0))
before = termios.tcgetattr(slave)
process = subprocess.Popen(['node', str(Path(__file__).with_name('play.js'))],
                           stdin=slave, stdout=slave, stderr=slave,
                           env={**os.environ, 'TERM': 'xterm'})
data = bytearray()

def receive_until(text, timeout=4):
    deadline = time.monotonic() + timeout
    start = len(data)
    while text.encode() not in data[start:]:
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise AssertionError('Missing terminal output: ' + text)
        if select.select([master], [], [], remaining)[0]:
            try:
                chunk = os.read(master, 65536)
            except OSError as error:
                if error.errno == errno.EIO:
                    break
                raise
            if not chunk:
                break
            data.extend(chunk)
    assert text.encode() in data[start:], text

try:
    receive_until('Choose mode:')
    assert not termios.tcgetattr(slave)[3] & termios.ICANON
    os.write(master, b'1' + b'4' + b'\r')
    receive_until('Practice / Easy / 4 dots')
    os.write(master, b'13')
    receive_until('Current: 1-2-3')
    os.write(master, b'1')
    receive_until('Selection unchanged.')
    os.write(master, b'm3\r')
    receive_until('Countdown: 60')
    receive_until('Countdown: 59', timeout=3)
    # Resize while running: drawing is bounded and Q remains available.
    fcntl.ioctl(slave, termios.TIOCSWINSZ, struct.pack('HHHH', 20, 60, 0, 0))
    process.send_signal(__import__('signal').SIGWINCH)
    receive_until('BreakLock needs 80 columns x 24 rows.')
    fcntl.ioctl(slave, termios.TIOCSWINSZ, struct.pack('HHHH', 24, 80, 0, 0))
    process.send_signal(__import__('signal').SIGWINCH)
    receive_until('Countdown:')
    os.write(master, b'q')
    receive_until('BreakLock closed. No progress saved.')
    assert process.wait(timeout=3) == 0
    after = termios.tcgetattr(slave)
    flags = termios.ICANON | termios.ECHO | termios.ISIG
    assert after[3] & flags == before[3] & flags
    assert b'\x1b[?25h\x1b[?1049l' in data
    frames = data.decode().split('\x1b[H\x1b[2J')[1:]
    # Exclude final alternate-screen restoration message from frame dimensions.
    # PTY ONLCR can expand our CRLF to CR-CR-LF; CR adds no printed column.
    screens = [[line.rstrip('\r') for line in frame.split('\x1b')[0].split('\r\n')]
               for frame in frames]
    assert all(len(lines) <= 23 for lines in screens)
    assert all(len(line) <= 78 for lines in screens for line in lines)
    result = {'result': 'PASS', 'ptyColumns': 80, 'ptyRows': 24,
              'frames': len(screens), 'maxFrameRows': max(map(len, screens)),
              'maxLineWidth': max(len(line) for lines in screens for line in lines),
              'realCountdownCallback': True, 'midpointVisible': True,
              'resizeHandled': True, 'quitExitCode': 0, 'terminalModesRestored': True}
    (output / 'pty-result.json').write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps(result, indent=2))
finally:
    if process.poll() is None:
        process.terminate()
        try:
            process.wait(timeout=3)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait(timeout=3)
    (output / 'pty-transcript.ansi').write_bytes(data)
    os.close(master)
    os.close(slave)
