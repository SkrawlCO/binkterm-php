"""Local real-PTY acceptance. All exported game data stays in a temporary directory."""
import os, pty, fcntl, termios, struct, subprocess, tempfile, json, select, time, re, signal
from pathlib import Path
ROOT=Path(__file__).resolve().parent
TMP=Path(tempfile.mkdtemp(prefix='wordwright-terminal-'))
subprocess.run(['node','fixtures.mjs',str(TMP)],cwd=ROOT,check=True)
frames=[];runs=0
class Run:
 def __init__(self,source,out):
  global runs
  runs+=1;self.master,self.slave=pty.openpty();fcntl.ioctl(self.slave,termios.TIOCSWINSZ,struct.pack('HHHH',24,80,0,0));self.before=termios.tcgetattr(self.slave);self.data=b'';self.out=out
  self.p=subprocess.Popen(['node','cli.mjs','--restore',str(source),'--export-on-exit',str(out)],cwd=ROOT,stdin=self.slave,stdout=self.slave,stderr=self.slave,env={**os.environ,'TERM':'xterm-256color'})
  self.read(.4);assert b'WORDWRIGHT' in self.data
 def read(self,wait=.12):
  end=time.monotonic()+wait
  while time.monotonic()<end:
   if select.select([self.master],[],[],max(0,end-time.monotonic()))[0]:
    try:self.data+=os.read(self.master,65536)
    except OSError:break
 def send(self,text):os.write(self.master,text.encode());self.read()
 def menu(self,key):self.send('\x1b');self.read(.6);self.send(key)
 def close(self,method='menu'):
  if method=='menu':self.menu('Q')
  elif method=='ctrlc':self.send('\x03')
  else:self.p.send_signal(signal.SIGTERM)
  self.p.wait(timeout=3);self.read(.1);assert self.p.returncode==0,self.data.decode(errors='replace')
  assert termios.tcgetattr(self.slave)==self.before,'terminal attributes not restored'
  assert self.data.endswith(b'\x1b[0m\x1b[?25h\x1b[?1049l'),'missing cursor/alternate-screen teardown'
  text=self.data.decode();parts=text.split('\x1b[H\x1b[2J')[1:]
  for frame in parts:
   clean=re.sub(r'\x1b\[[0-9;?]*[A-Za-z]','',frame).replace('\r\r\n','\r\n');assert '\x1b' not in clean
   lines=clean.split('\r\n');assert len(lines)<=24,(len(lines),clean);assert max(map(len,lines))<=80
   frames.append(clean)
  (TMP/f'run-{runs}.ansi').write_bytes(self.data)
  os.close(self.master);os.close(self.slave)
  return json.loads(self.out.read_text())
# Each geometry and command collision; leave partial input for separate-process restore.
for length in [4,5,6]:
 r=Run(TMP/f'initial-{length}.json',TMP/f'partial-{length}.json');r.send('NLSQ');snap=r.close();assert snap['game']['currentInput']=='NLSQ';assert snap['selectedLength']==length
# Restore partial state then backspace, valid duplicate feedback, win, stats, next games.
r=Run(TMP/'partial-5.json',TMP/'won.json');assert b'Guess: NLSQ' in r.data
r.send('\x7f'*4);r.send('ZZZZZ\r');assert b'Not in word list' in r.data
r.send('\x7f'*5);r.send('ALLEY\r');plain=re.sub(rb'\x1b\[[0-9;?]*[A-Za-z]',b'',r.data);assert b'A=  L+  L-  E+  Y-' in plain
r.send('APPLE\r');assert b'SOLVED!' in r.data;r.send('NLSQ');r.menu('S');assert b'Played 1 | Won 1' in r.data;r.send('\x1b');r.read(.6)
won=r.close();assert won['game']['status']=='won';assert won['game']['currentInput']=='';assert won['stats']['overall']['gamesWon']==1
# Completed/stats restoration and successive new games; change length.
r=Run(TMP/'won.json',TMP/'new-length.json');assert b'SOLVED!' in r.data;r.menu('S');assert b'Played 1 | Won 1' in r.data;r.send('\x1b');r.read(.6)
for i in range(3):r.menu('N')
r.menu('L');r.send('6');assert b'6 letters' in r.data
new=r.close('ctrlc');assert new['selectedLength']==6;assert new['stats']==won['stats'];assert len(new['meta']['recentAnswers']['5'])==4
# Six-attempt loss and signal cleanup.
r=Run(TMP/'initial-5.json',TMP/'loss.json')
for i in range(6):r.send('ALLEY\r')
assert b'Answer: APPLE' in r.data
r.send('Q');loss=r.close('signal');assert loss['game']['status']=='lost';assert len(loss['game']['guesses'])==6
# Partial typed answer survives process boundary, then continue and submit in fresh process.
r=Run(TMP/'initial-4.json',TMP/'prefix.json');answer=json.loads((TMP/'initial-4.json').read_text())['game']['answer'];r.send(answer[:2]);prefix=r.close()
r=Run(TMP/'prefix.json',TMP/'four-won.json');assert ('Guess: '+answer[:2]).encode() in r.data;r.send(answer[2:]+'\r');final=r.close();assert final['game']['status']=='won'
print(f'PASS {runs} real PTY runs; {len(frames)} frames; maximum {max(max(map(len,f.split(chr(13)+chr(10)))) for f in frames)} columns x {max(len(f.split(chr(13)+chr(10))) for f in frames)} rows')
print('PASS 4/5/6, command safety, invalid/valid, duplicates, win/loss, stats, new games, fresh-process restore, Ctrl-C/SIGTERM, termios/cursor cleanup')
print('Evidence:',TMP)
