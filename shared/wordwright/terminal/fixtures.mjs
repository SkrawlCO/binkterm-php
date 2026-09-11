// Deterministic real dictionary fixtures for local PTY tests only.
import { mkdir,writeFile } from 'node:fs/promises';
import { WordwrightSession } from '../dist/session.js';
import { loadDictionary } from '../dist/upstream/src/dictionary/loadDictionary.js';
const dir=process.argv[2];if(!dir)throw new Error('Supply scratch fixture directory');await mkdir(dir,{recursive:true});
for(const length of [4,5,6]){
 const dictionary=await loadDictionary(length);
 const session=await WordwrightSession.create(length,{id:()=>`pty-${length}`,random:{next:()=>length===5?(dictionary.answers.indexOf('APPLE')+.1)/dictionary.answers.length:0}});
 await writeFile(`${dir}/initial-${length}.json`,JSON.stringify(session.snapshot()));
}
