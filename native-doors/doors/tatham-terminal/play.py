#!/usr/bin/env python3
"""Thin terminal presentation; upstream owns state and PHP owns persistence."""
import json
import os
import select
import signal
import subprocess
import sys
import termios
import time
import tty
from pathlib import Path

BASE = Path(__file__).resolve().parent
ROOT = BASE.parents[2]


class Peer:
    def __init__(self, command):
        # Neither the game nor this transport needs database credentials.
        env = {k: os.environ[k] for k in ('PATH', 'TERM', 'DOOR_USER_NUMBER') if k in os.environ}
        self.process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                        stderr=subprocess.DEVNULL, env=env, bufsize=0)
        self.buffer = b''

    def read(self):
        deadline = time.monotonic() + 6
        while b'\n' not in self.buffer:
            remaining = deadline - time.monotonic()
            if remaining <= 0 or not select.select([self.process.stdout], [], [], remaining)[0]:
                raise RuntimeError('unavailable')
            chunk = os.read(self.process.stdout.fileno(), 16384)
            if not chunk or len(self.buffer) + len(chunk) > 150000:
                raise RuntimeError('unavailable')
            self.buffer += chunk
        line, self.buffer = self.buffer.split(b'\n', 1)
        return json.loads(line)

    def send(self, data):
        view = memoryview(data)
        while view:
            count = self.process.stdin.write(view)
            if not count:
                raise RuntimeError('unavailable')
            view = view[count:]
        return self.read()

    def request(self, action, **fields):
        result = self.send((json.dumps(dict(action=action, **fields)) + '\n').encode())
        if not result.get('success'):
            raise RuntimeError(result.get('reason', 'unavailable'))
        return result

    def close(self):
        if self.process.poll() is None:
            self.process.stdin.close()
            try:
                self.process.wait(timeout=1)
            except subprocess.TimeoutExpired:
                self.process.terminate()
                try:
                    self.process.wait(timeout=1)
                except subprocess.TimeoutExpired:
                    self.process.kill()
                    self.process.wait()

def cells(state):
    # Parse the upstream ASCII grid, not the puzzle definition or rules.
    raw=state['ascii'].splitlines()
    assert len(raw)==15 and all(len(row)==15 for row in raw)
    return [[raw[2*r+1][2*c+1] for c in range(7)] for r in range(7)]
def screen(state,feedback='Ready'):
    board=cells(state);cursor=state['cursor']
    lines=['Light Up / 7x7 Tricky / canonical engine', '    1    2    3    4    5    6    7']
    for r in range(7):
        lines.append('  +'+'----+'*7)
        row=f'{r+1} |'
        for c in range(7):
            glyph=board[r][c] if board[r][c]!=' ' else '_'
            # These flags are emitted by canonical drawing callbacks, never calculated.
            flag='!' if state['errors'][r][c] else '+' if glyph=='x' and state['lit'][r][c] else ' '
            content=glyph+flag
            row+=('['+content+']' if cursor==[c,r] else ' '+content+' ')+'|'
        lines.append(row)
    lines.append('  +'+'----+'*7)
    lines += ['L=bulb  #=wall  0-4=clue  _=unlit  .=lit',
              'x=no-bulb mark  x+=marked AND lit  !=backend error  [ ]=cursor',
              'Goal: light every white cell; satisfy clues; bulbs must not see each other.',
              'Status: '+('COMPLETE' if state['status']==1 else 'in progress')+' | '+(state['error'] or feedback),
              'WASD move | Space bulb | x mark | u undo | y redo',
              'r restart | ? help | v Save | q Save & Return']
    assert len(lines)<=24 and max(map(len,lines))<80
    return lines

def main():
    if len(sys.argv) != 1 or not sys.stdin.isatty() or not sys.stdout.isatty():
        return 2
    size = os.get_terminal_size(sys.stdout.fileno())
    if size.columns < 80 or size.lines < 24:
        print('Tatham requires at least 80 columns by 24 rows.')
        return 2
    helper = engine = old = None
    interrupted = False
    def hangup(signum, frame):
        nonlocal interrupted
        interrupted = True
    for sig in (signal.SIGHUP, signal.SIGTERM, signal.SIGINT):
        signal.signal(sig, hangup)
    try:
        helper = Peer(['php', str(ROOT / 'scripts/tatham-progress.php')])
        lease = helper.request('acquire')
        engine = Peer([str(BASE / 'terminal-engine')])
        state = engine.read()
        acknowledged = lease['data'].get('payload') if isinstance(lease['data'], dict) else None
        if acknowledged:
            raw = acknowledged.encode('utf-8')
            state = engine.send(('import %d\n' % len(raw)).encode() + raw)
            if state['error'] or state['save'] != acknowledged:
                raise RuntimeError('canonical load mismatch')
        revision = lease['revision']
        def checkpoint():
            nonlocal acknowledged, revision
            if state['save'] != acknowledged:
                result = helper.request('save', revision=revision, payload=state['save'])
                if result['data']['payload'] != state['save']:
                    raise RuntimeError('canonical acknowledgment mismatch')
                revision = result['revision']
                acknowledged = state['save']
        checkpoint()  # Initial state is acknowledged before enabling input.
        old = termios.tcgetattr(sys.stdin)
        tty.setraw(sys.stdin.fileno())
        sys.stdout.write('\x1b[?1049h\x1b[?25l')
        last_checkpoint = last_renew = time.monotonic()
        feedback = 'Saved revision %d' % revision
        redraw = True
        while not interrupted:
            if redraw:
                sys.stdout.write('\x1b[2J\x1b[H' + '\r\n'.join(screen(state, feedback)))
                sys.stdout.flush()
                redraw = False
            now = time.monotonic()
            if now - last_checkpoint >= 1:
                checkpoint()
                last_checkpoint = now
                feedback = 'Saved revision %d' % revision
                redraw = True
            if now - last_renew >= 10:
                helper.request('renew')
                last_renew = now
            if not select.select([sys.stdin], [], [], 0.1)[0]:
                continue
            key = os.read(sys.stdin.fileno(), 1).decode('ascii', errors='ignore')
            if not key:
                interrupted = True
                break
            if key == '\x1b':
                tail = b''
                for _ in range(2):
                    if select.select([sys.stdin], [], [], 0.04)[0]:
                        tail += os.read(sys.stdin.fileno(), 1)
                key = {b'[A': 'w', b'[B': 's', b'[C': 'd', b'[D': 'a'}.get(tail, '')
            if key in ('q', '\x03', '\x04'):
                checkpoint()
                helper.request('release')
                return 0
            if key == 'v':
                checkpoint()
                feedback = 'Saved revision %d' % revision
            elif key == '?':
                feedback = 'Space bulb; x mark; u/y undo/redo; q saves and returns'
            elif key in 'wasd xuyr' and key:
                state = engine.send(('restart\n' if key == 'r' else 'key ' + key + '\n').encode())
                if state['error']:
                    raise RuntimeError('engine rejected input')
            redraw = True
        # Hangup is best effort; a failed write never replaces the acknowledged save.
        try:
            checkpoint()
            helper.request('release')
        except Exception:
            pass  # Unreleased ownership expires through the shared lease contract.
        return 0
    except Exception as error:
        message = ('Another surface owns this puzzle. Please return later.'
                   if str(error) == 'conflict' else
                   'Tatham stopped. Last acknowledged progress is preserved.')
        if not interrupted:
            sys.stdout.write('\r\n' + message + '\r\n')
        return 3
    finally:
        if old is not None:
            try:
                termios.tcsetattr(sys.stdin, termios.TCSANOW, old)
                sys.stdout.write('\x1b[0m\x1b[?25h\x1b[?1049l\r\n')
                sys.stdout.flush()
            except OSError:
                pass
        for peer in (engine, helper):
            if peer is not None:
                peer.close()


if __name__ == '__main__':
    raise SystemExit(main())
