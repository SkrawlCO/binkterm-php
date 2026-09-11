"""Real PTY acceptance. Python standard library only; no simulated input transport."""
import errno
import fcntl
import json
import os
from pathlib import Path
import re
import select
import signal
import struct
import subprocess
import termios
import time
import unittest

ROOT = Path(__file__).resolve().parent
OUT = ROOT / 'test-results'
OUT.mkdir(exist_ok=True)
subprocess.run(['node', str(ROOT / 'fixtures.mjs'), str(OUT)], check=True)

class Terminal:
    def __init__(self, restore=None, name='session', colour=False):
        self.master, self.slave = os.openpty()
        self.size(80, 24, notify=False)
        self.before = termios.tcgetattr(self.slave)
        self.data = b''
        self.name = name
        self.snapshot = OUT / (name + '-export.json')
        if self.snapshot.exists():
            self.snapshot.unlink()
        args = ['node', str(ROOT / 'cli.mjs'), '--snapshot', str(self.snapshot)]
        if restore:
            args += ['--restore', str(restore)]
        env = {**os.environ, 'TERM': 'xterm-256color'}
        if not colour:
            env['NO_COLOR'] = '1'
        else:
            env.pop('NO_COLOR', None)
        self.process = subprocess.Popen(args, stdin=self.slave, stdout=self.slave, stderr=self.slave, env=env)
        self.read(.25)
    def size(self, columns, rows, notify=True):
        fcntl.ioctl(self.slave, termios.TIOCSWINSZ, struct.pack('HHHH', rows, columns, 0, 0))
        if notify:
            self.process.send_signal(signal.SIGWINCH)
            self.read(.2)
    def read(self, duration=.12):
        until = time.monotonic() + duration
        while time.monotonic() < until:
            ready, _, _ = select.select([self.master], [], [], max(0, until-time.monotonic()))
            if not ready:
                break
            try:
                block = os.read(self.master, 65536)
            except OSError as error:
                if error.errno == errno.EIO:
                    break
                raise
            if not block:
                break
            self.data += block
    def send(self, keys, delay=.15):
        os.write(self.master, keys if isinstance(keys, bytes) else keys.encode())
        self.read(delay)
    def frame(self):
        frame = self.data.decode('utf8', errors='strict').split('\x1b[H\x1b[2J')[-1]
        frame = re.sub(r'\x1b\[[0-9;]*m', '', frame)
        matches = list(re.finditer(r'\x1b\[(\d+);1H', frame))
        lines = []
        for i, match in enumerate(matches):
            row = int(match.group(1))
            segment = frame[match.end():matches[i+1].start() if i+1<len(matches) else len(frame)]
            segment = segment.split('\x1b')[0]
            assert row <= 24, (row, segment)
            assert len(segment) <= 80, (len(segment), segment)
            assert all(32 <= ord(c) <= 126 for c in segment), repr(segment)
            while len(lines) < row:
                lines.append('')
            lines[row-1] = segment
        return '\n'.join(lines)
    def capture(self, label):
        frame = self.frame()
        (OUT / (label + '.txt')).write_text(frame + '\n')
        return frame
    def export(self):
        self.send(b'\x1b', .65)
        assert 'MENU' in self.frame(), self.frame()
        self.send('e')
        assert 'Snapshot exported' in self.frame(), self.frame()
        return json.loads(self.snapshot.read_text())
    def close(self, how='quit'):
        if self.process.poll() is None:
            if how == 'quit':
                if 'MENU' not in self.frame():
                    self.send(b'\x1b', .65)
                self.send('q')
            elif how == 'ctrl-c':
                self.send(b'\x03')
            else:
                self.process.send_signal(signal.SIGTERM)
            self.process.wait(timeout=3)
        self.read(.1)
        after = termios.tcgetattr(self.slave)
        assert after == self.before, 'Terminal modes not restored'
        if b'\x1b[?1049h' in self.data:
            assert self.data.endswith(b'\x1b[0m\x1b[?25h\x1b[?1049l'), self.data[-100:]
            length = len(self.data)
            self.read(.3)
            assert len(self.data) == length, 'Output after cleanup'
        (OUT / (self.name + '.ansi')).write_bytes(self.data)
        os.close(self.master)
        os.close(self.slave)
        return self.process.returncode

class Proof(unittest.TestCase):
    def terminal(self, *args, **kwargs):
        t = Terminal(*args, **kwargs)
        def cleanup():
            if t.process.poll() is None:
                try:
                    t.close('ctrl-c')
                finally:
                    if t.process.poll() is None:
                        t.process.kill()
                        t.process.wait()
        self.addCleanup(cleanup)
        return t
    def test_01_modes_and_new_puzzle(self):
        t = self.terminal(name='modes')
        for key, name in [('1','EASY'),('2','MEDIUM'),('3','HARD'),('4','EXPERT'),('5','Daily')]:
            if key != '1':
                t.send(b'\x1b',.65)
            t.send(key,.4)
            if key != '1':
                self.assertIn('Replace current',t.frame())
                t.send('y',.5)
            self.assertIn(name,t.frame())
            t.capture('mode-'+key)
        saved=t.export()
        self.assertEqual(saved['identity']['kind'],'daily')
        self.assertEqual(saved['difficulty'],'medium')
        self.assertEqual(t.close(),0)
        restored=self.terminal(t.snapshot,name='daily-restore')
        self.assertEqual(restored.export(),saved)
        restored.close()
    def test_02_notes_entries_conflicts_hints_and_fresh_restore(self):
        t = self.terminal(OUT/'partial.json',name='actions',colour=True)
        t.capture('normal')
        t.send(b'\x1b[C')
        self.assertIn('Selected: A2',t.frame())
        t.send('n5');self.assertIn('Notes: 5',t.capture('notes'))
        t.send('5');self.assertIn('Notes: (none)',t.frame())
        t.send('5na5');self.assertNotIn(' : ','\n'.join(t.frame().splitlines()[4:17]))
        t.send('u');t.send('d');self.assertIn('Notes: 5',t.frame())
        t.send('a3');self.assertIn('CONFLICT',t.capture('conflict'))
        self.assertIn(b'\x1b[31m',t.data)
        t.send('h');self.assertIn('Mistake',t.capture('mistake'))
        t.send(b'\r');t.send('x0h');self.assertIn('Naked Single',t.capture('hint'))
        t.send(b'\r');t.send('x5dn3nh');self.assertIn('Naked Single',t.frame())
        t.send(b'\r');t.read(1.1);t.send('p');self.assertIn('Puzzle hidden',t.capture('pause'))
        saved=t.export()
        self.assertGreaterEqual(saved['elapsedMs'],1000)
        self.assertTrue(saved['paused'])
        t.close()
        fresh=self.terminal(t.snapshot,name='fresh-restore')
        copied=fresh.export()
        self.assertEqual(copied,saved)
        fresh.send('rp');self.assertIn('5+',fresh.frame());self.assertIn('Notes: 3',fresh.frame())
        self.assertIn('Hint: Naked Single',fresh.frame())
        fresh.send('u');self.assertIn('Notes: (none)',fresh.frame());self.assertIn('5+',fresh.frame())
        fresh.close()
    def test_03_completion_and_completed_restore(self):
        t=self.terminal(OUT/'last.json',name='completed')
        t.send('5');self.assertIn('PUZZLE COMPLETE!',t.capture('completion'))
        saved=t.export();t.close()
        fresh=self.terminal(t.snapshot,name='completed-restore')
        self.assertIn('PUZZLE COMPLETE!',fresh.frame())
        self.assertEqual(fresh.export(),saved);fresh.close()
    def test_04_reveal_and_paged_help(self):
        t=self.terminal(OUT/'reveal.json',name='reveal')
        t.send('h');self.assertIn('Reveal',t.capture('reveal'))
        t.send(b'\r');t.send('?');self.assertIn('Page 1/',t.frame())
        t.send(b'\x1b[C');self.assertIn('Page 2/',t.capture('help-page2'))
        t.close()
    def test_05_resize_pause_resume(self):
        t=self.terminal(OUT/'partial.json',name='resize')
        t.size(60,18);self.assertIn('resize to at least',t.frame())
        self.assertLessEqual(max(map(len,t.frame().splitlines())),59)
        t.size(100,30);self.assertIn('PAUSED',t.frame());t.send('p')
        self.assertIn('PLAYING',t.frame());t.size(80,24);t.capture('resized');t.close()
    def test_06_ctrl_c_restores_terminal(self):
        t=self.terminal(name='ctrl-c');self.assertEqual(t.close('ctrl-c'),130)
    def test_07_sigterm_restores_terminal(self):
        t=self.terminal(name='sigterm');self.assertEqual(t.close('sigterm'),143)
    def test_08_bad_restore_leaves_terminal_untouched(self):
        bad=OUT/'bad.json';bad.write_text('{broken')
        t=self.terminal(bad,name='invalid')
        t.process.wait(timeout=3)
        self.assertNotIn(b'\x1b[?1049h',t.data)
        self.assertEqual(t.close(),2)
    def test_09_real_backspace_delete_and_zero(self):
        t=self.terminal(OUT/'partial.json',name='erase')
        for key in [b'\x7f',b'\x1b[3~',b'0']:
            t.send('5');t.send(key);self.assertIn('[.]',t.frame())
        t.close()

if __name__ == '__main__':
    unittest.main(verbosity=2)
