"""Run with TATHAM_NATIVE_TEST=1 only inside the disposable Slice 4 app fixture."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


class TathamNativeProgressTest(unittest.TestCase):
    def setUp(self):
        self.assertEqual(os.environ.get('TATHAM_NATIVE_TEST'), '1')
        # Fail closed instead of falling back to the application's production DB.
        self.assertIn('DB_NAME=tatham_slice4', (ROOT / '.env').read_text())
        self.peers = []

    def tearDown(self):
        for process in self.peers:
            process.stdin.close()
            try:
                process.wait(timeout=3)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()
            process.stdout.close()

    def start(self, identity=None, args=()):
        env = {'PATH': os.environ['PATH']}
        if identity is not None:
            env['DOOR_USER_NUMBER'] = identity
        process = subprocess.Popen(['php', str(ROOT / 'scripts/tatham-progress.php'), *args],
                                   stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                   stderr=subprocess.DEVNULL, text=True, env=env)
        self.peers.append(process)
        return process

    def call(self, process, action, **fields):
        process.stdin.write(json.dumps(dict(action=action, **fields)) + '\n')
        process.stdin.flush()
        return json.loads(process.stdout.readline())

    def test_invalid_identity_and_argv_fail_closed(self):
        for identity in (None, '', '0', '-1', 'caller', '9999999999'):
            process = self.start(identity)
            result = json.loads(process.stdout.readline())
            self.assertEqual(result, {'success': False, 'reason': 'identity'})
            self.assertEqual(process.wait(timeout=3), 2)
        process = self.start('1', ('2',))
        self.assertEqual(json.loads(process.stdout.readline())['reason'], 'identity')

    def test_shared_service_conflict_exactness_and_isolation(self):
        a, competing, b = self.start('1'), self.start('1'), self.start('2')
        lease = self.call(a, 'acquire')
        self.assertTrue(lease['success'])
        self.assertNotIn('owner_token', lease)
        self.assertEqual(self.call(competing, 'acquire')['reason'], 'conflict')
        other = self.call(b, 'acquire')
        self.assertTrue(other['success'])
        self.assertNotEqual(lease['attempt_id'], other['attempt_id'])
        self.assertFalse(other['data'])
        # Obtain a real save from the pinned engine rather than inventing its format.
        engine = subprocess.run([str(ROOT / 'native-doors/doors/tatham-terminal/terminal-engine')],
                                input='key  \nkey d\nkey x\nquit\n', text=True, capture_output=True, check=True)
        payload = json.loads(engine.stdout.splitlines()[-1])['save']
        saved = self.call(a, 'save', revision=lease['revision'], payload=payload)
        self.assertTrue(saved['success'])
        self.assertEqual(saved['data']['payload'], payload)
        self.assertEqual(saved['revision'], lease['revision'] + 1)
        self.assertFalse(self.call(a, 'save', revision=lease['revision'], payload=payload)['success'])
        self.assertEqual(self.call(a, 'renew')['data']['payload'], payload)
        self.assertEqual(self.call(a, 'acquire', user_id=2)['reason'], 'input')
        self.assertEqual(self.call(a, 'nonsense')['reason'], 'input')
        self.assertTrue(self.call(a, 'release')['success'])
        successor = self.call(competing, 'acquire')
        self.assertEqual(successor['data']['payload'], payload)
        self.assertFalse(self.call(a, 'release')['success'])
        self.assertTrue(self.call(competing, 'release')['success'])
        self.assertTrue(self.call(b, 'release')['success'])

    def test_every_rendered_frame_fits_classic_geometry(self):
        path = ROOT / 'native-doors/doors/tatham-terminal/play.py'
        spec = importlib.util.spec_from_file_location('tatham_play', path)
        play = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(play)
        result = subprocess.run([str(path.parent / 'terminal-engine')],
                                input='key  \nkey d\nkey x\nkey u\nkey y\nrestart\nquit\n',
                                text=True, capture_output=True, check=True)
        for frame in result.stdout.splitlines():
            state = json.loads(frame)
            for status in ('Saved revision 100', 'Space bulb; x mark; u/y undo/redo; q saves and returns'):
                lines = play.screen(state, status)
                self.assertLessEqual(len(lines), 24)
                self.assertLess(max(map(len, lines)), 80)


if __name__ == '__main__':
    unittest.main()
