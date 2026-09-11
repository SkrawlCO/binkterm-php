"""Real persistent CLI lifecycle against isolated PostgreSQL caller A; no production launcher."""
import os, pty, fcntl, termios, struct, subprocess, select, time, json
from pathlib import Path
caller=json.loads(Path('/tmp/dokuel-persistence/callers.json').read_text())['callers'][0]['id']
root='/var/www/html/shared/dokuel'
def request(body):
    p=subprocess.run(['docker','exec','-i','-e',f'DOOR_USER_NUMBER={caller}','binkterm-app','php',root+'/persistence/helper.php'],input=json.dumps(body)+'\n',text=True,capture_output=True,check=True)
    return json.loads(p.stdout.splitlines()[0])
def run(mode):
    before=request({'action':'acquire'});assert before['success']
    m,s=pty.openpty();fcntl.ioctl(s,termios.TIOCSWINSZ,struct.pack('HHHH',24,80,0,0));original=termios.tcgetattr(s)
    command='echo $$ > /tmp/dokuel-persistent-proof.pid; exec node '+root+'/terminal/persistent.mjs'
    p=subprocess.Popen(['docker','exec','-it','-e',f'DOOR_USER_NUMBER={caller}','binkterm-app','sh','-c',command],stdin=s,stdout=s,stderr=s)
    output=b''
    def drain(seconds):
        nonlocal output
        until=time.monotonic()+seconds
        while time.monotonic()<until:
            if select.select([m],[],[],0.05)[0]:
                try: output+=os.read(m,65536)
                except OSError: break
    try:
        drain(2);assert b'DOKUEL' in output,output[-1000:]
        os.write(m,b'\x1b');drain(.6)
        if mode=='quit':os.write(m,b'q')
        else:subprocess.run(['docker','exec','binkterm-app','sh','-c','kill -TERM "$(cat /tmp/dokuel-persistent-proof.pid)"'],check=True)
        drain(2);p.wait(timeout=12);assert p.returncode==(0 if mode=='quit' else 143),output[-1200:]
        assert b'\x1b[?25h\x1b[?1049l' in output
        assert termios.tcgetattr(s)==original
        after=request({'action':'acquire'});assert after['success'];assert after['revision']>before['revision'];assert after['data']['puzzle']==before['data']['puzzle'];assert after['data']['paused']
        Path('/tmp/dokuel-persistence/'+mode+'.ansi').write_bytes(output)
        print('PASS persistent real PTY '+mode+': saved, released, canonical identity retained, terminal restored')
    finally:
        if p.poll() is None:p.kill();p.wait()
        os.close(m);os.close(s)
run('quit');run('sigterm')
