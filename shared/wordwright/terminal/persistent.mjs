// Trusted local launcher only; no caller or snapshot command-line arguments.
import { helperTransport } from '../persistence/node-transport.mjs';
import { connectTerminal } from '../persistence/terminal.mjs';
import { runTerminal } from './cli.mjs';
if(process.argv.length!==2)throw Error('No caller arguments accepted');
const helper=helperTransport();let close;
try {
 const {adapter,lease}=await connectTerminal(helper.request,{onError:()=>close?.()});
 close=runTerminal(adapter,{onExit:()=>{
    lease.release().catch(error=>{console.error(`Wordwright save stopped: ${error.message}`);process.exitCode=1;}).finally(()=>helper.close());
 }});
} catch(error){helper.close();console.error(error.message);process.exitCode=1;}
