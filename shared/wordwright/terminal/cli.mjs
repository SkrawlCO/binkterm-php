import { emitKeypressEvents } from 'node:readline';
import { readFileSync, writeFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import { WordwrightSession } from '../dist/session.js';
import { TerminalAdapter } from './adapter.mjs';

export function runTerminal(adapter, {input=process.stdin,output=process.stdout,onExit=()=>{},signals=process}={}) {
    if(!input.isTTY||!output.isTTY)throw new Error('An interactive terminal is required.');
    const wasRaw=!!input.isRaw; let closed=false;
    const draw=()=>{
        const frame=(output.columns<80||output.rows<24)
            ? 'Wordwright needs 80 columns x 24 rows. Resize or Ctrl-C to quit.' : adapter.render();
        output.write('\x1b[H\x1b[2J'+frame);
    };
    const close=()=>{
        if(closed)return;closed=true;
        input.removeListener('keypress',key);output.removeListener('resize',draw);
        signals.removeListener('SIGINT',close);signals.removeListener('SIGTERM',close);signals.removeListener('SIGHUP',close);
        try {onExit(adapter.prepareHandoff());}
        finally {input.setRawMode(wasRaw);input.pause();output.write('\x1b[0m\x1b[?25h\x1b[?1049l');}
    };
    const key=(text,key)=>{try {if(adapter.handle(text,key)==='quit')close();else draw();}catch(error){close();throw error;}};
    emitKeypressEvents(input);input.setRawMode(true);input.resume();
    input.on('keypress',key);output.on('resize',draw);
    for(const signal of ['SIGINT','SIGTERM','SIGHUP'])signals.on(signal,close);
    output.write('\x1b[?1049h\x1b[?25l');draw();return close;
}
async function main(){
    const args=process.argv.slice(2);let restore,save,length=5,color=!process.env.NO_COLOR;
    while(args.length){const arg=args.shift();
        if(arg==='--restore')restore=args.shift();else if(arg==='--export-on-exit')save=args.shift();
        else if(arg==='--length')length=Number(args.shift());else if(arg==='--no-color')color=false;
        else throw new Error('Usage: node cli.mjs [--length 4|5|6] [--restore file] [--export-on-exit file] [--no-color]');
        if((arg==='--restore'&&!restore)||(arg==='--export-on-exit'&&!save))throw new Error('Missing file path');
    }
    const session=restore ? await WordwrightSession.restore(JSON.parse(readFileSync(restore,'utf8'))) : await WordwrightSession.create(length);
    runTerminal(new TerminalAdapter(session,{color}),{onExit:snapshot=>{if(save)writeFileSync(save,JSON.stringify(snapshot,null,2)+'\n',{mode:0o600});}});
}
if(process.argv[1]&&import.meta.url===pathToFileURL(process.argv[1]).href)main().catch(error=>{console.error(error.message);process.exitCode=1;});
