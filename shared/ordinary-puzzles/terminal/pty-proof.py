"""Bounded stdlib-only 80x24 PTY, restart/restore and terminal cleanup proof."""
import os, pty, termios, fcntl, struct, subprocess, select, time, re, json, tempfile
from pathlib import Path
root = Path(__file__).resolve().parent
out = Path(tempfile.mkdtemp(prefix='ordinary-terminal-'))

def launch(args, keys, name):
    master, slave = pty.openpty()
    fcntl.ioctl(slave, termios.TIOCSWINSZ, struct.pack('HHHH', 24, 80, 0, 0))
    before = termios.tcgetattr(slave)
    proc = subprocess.Popen(['node', str(root/'cli.cjs')] + args, stdin=slave, stdout=slave, stderr=slave)
    data = b''
    def drain(seconds):
        nonlocal data
        end = time.monotonic()+seconds
        while time.monotonic()<end:
            if select.select([master], [], [], .03)[0]:
                try: data += os.read(master, 65536)
                except OSError: break
    drain(.5)
    for key in keys:
        os.write(master, key); drain(.08)
    proc.wait(timeout=5); drain(.1)
    assert proc.returncode == 0, data[-2000:]
    assert termios.tcgetattr(slave) == before, 'terminal modes not restored'
    assert b'\x1b[?25h\x1b[?1049l' in data, 'cursor/alternate screen not restored'
    text = data.decode()
    frames = text.split('\x1b[H')[1:]
    assert frames
    for frame in frames:
        body = frame.split('\x1b[J')[0]
        lines = body.replace('\r','').split('\n')
        assert len(lines)<=24, len(lines)
        assert all(len(line)<=80 for line in lines)
        assert '\x1b' not in body
    residual = re.sub(r'\x1b\[[?0-9;]*[A-Za-z]', '', text)
    assert '\x1b' not in residual, 'unrecognized escape sequence'
    (out/(name+'.ansi')).write_bytes(data)
    (out/(name+'.txt')).write_text(frames[-1].split('\x1b[J')[0].replace('\r',''))
    os.close(master); os.close(slave)
    print(name, 'PASS: 80x24 frames, exit 0, termios and cursor restored')

first=out/'first.json'; second=out/'second.json'; third=out/'third.json'
launch(['--export',str(first)], [b'd',b' ',b'd',b'd',b' ',b'd',b' ',b'q'], 'small-active')
a=json.loads(first.read_text()); assert a['verification']['interaction']['dragging']
assert a['verification']['lines'][0]['cells']==['0:1','0:2','0:3']
launch(['--restore',str(first),'--export',str(second)], [b'q'], 'restored-active')
assert json.loads(second.read_text())==a, 'cross-process restore parity'
launch(['--restore',str(second),'--export',str(third)], [b'd',b' ',b'q'], 'continued')
b=json.loads(third.read_text()); assert not b['verification']['interaction']['dragging']; assert len(b['actions'])>len(a['actions'])
launch([], [b'n',b'2',b'\r']+[b's']*9+[b'q'], 'medium-pan')
launch(['--puzzle','e653beb422ba'], [b's']*10+[b'q'], 'large-pan')
launch([], [b'\x03'], 'ctrl-c')
print('Cross-process JSON restore and continuation PASS')
print('Evidence:', out)
