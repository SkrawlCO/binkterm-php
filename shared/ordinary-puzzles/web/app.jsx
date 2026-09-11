import React,{useState,useEffect} from 'react';
import {LeasedSession,webTransport} from '../persistence/session.cjs';
import {createRoot} from 'react-dom/client';
import {Tile} from './upstream/Tile';
import {OrdinaryPuzzlesSession,catalog} from '../session.cjs';
let session=new OrdinaryPuzzlesSession('e9c2882a25e2');
let notify=()=>{}, pointer=null, frozen=Boolean(window.ordinaryPuzzlesStorage), lease=null;
let storageNotify=()=>{};
const writable=()=>{if(frozen)throw Error("Progress writer is inactive");};
const refresh=()=>notify(session.state());
const api={catalog,state:()=>session.state(),snapshot:()=>session.export(),
 load:id=>{writable();session.load(id);pointer=null;refresh();},
 restore:s=>{writable();session=OrdinaryPuzzlesSession.restore(s);pointer=null;refresh();},
 action:(type,...cell)=>{writable();const result=session[type](...cell);refresh();return result;}};
// Explicit review-host opt-in; no production route or service worker.
api.checkpoint=()=>lease.checkpoint();
api.exit=async()=>{const snapshot=await lease.exit();window.ordinaryPuzzlesStorage?.onExit?.();return snapshot;};
api.revision=()=>lease?.envelope?.revision;
api.connect=async options=>{
 if(lease)throw Error('Already connected');
 lease=new LeasedSession({snapshot:api.snapshot,
 suspend:()=>{frozen=true;pointer=null;return api.snapshot();},
 restore:s=>{if(s)session=OrdinaryPuzzlesSession.restore(s);frozen=false;refresh();}},
 webTransport(options.endpoint,options.csrfToken),{onError:e=>storageNotify('Progress storage stopped: '+e.message)});
 await lease.acquire();return true;
};
addEventListener('pagehide',()=>lease?.abandon());
window.ordinaryPuzzles=api;
function App(){
 const [state,setState]=useState(session.state()),[width,setWidth]=useState(innerWidth),[json,setJson]=useState(''),[error,setError]=useState('');
 notify=setState; storageNotify=setError;
 useEffect(()=>{api.ready=window.ordinaryPuzzlesStorage?api.connect(window.ordinaryPuzzlesStorage):Promise.resolve();api.ready.catch(e=>setError(e.message));},[]);
 useEffect(()=>{const f=()=>setWidth(innerWidth);addEventListener('resize',f);return()=>removeEventListener('resize',f);},[]);
 const size=Math.floor(Math.min((Math.min(width,680)-48)/state.columns,440/state.rows,60));
 const cellAt=e=>{const r=e.currentTarget.getBoundingClientRect();const col=Math.floor((e.clientX-r.left)/(r.width/state.columns)),row=Math.floor((e.clientY-r.top)/(r.height/state.rows));return row>=0&&row<state.rows&&col>=0&&col<state.columns?[row,col]:null;};
 // Pointer routing follows upstream onGridPointerMove/Up, using canonical hover.
 const move=e=>{if(frozen||pointer!==e.pointerId||!session.state().interaction.dragging)return;const cell=cellAt(e);if(!cell){api.action('exit');pointer=null;return;}const hovered=session.state().interaction.hovered;if(cell.join(':')!==hovered){if(hovered)api.action('leave',...hovered.split(':').map(Number));api.action('enter',...cell);}};
 const down=e=>{if(frozen||!state.interaction.enabled)return;e.preventDefault();const cell=cellAt(e);if(!cell)return;pointer=e.pointerId;e.currentTarget.setPointerCapture(pointer);if(!session.state().interaction.dragging)api.action('begin',...cell);else move(e);};
 const up=e=>{if(frozen||pointer!==e.pointerId)return;if(session.state().interaction.dragging){const cell=cellAt(e);if(cell)api.action('end',...cell);else api.action('exit');}pointer=null;};
 return <main><h1>Ordinary Puzzles</h1><p>Extend each number into a straight line of that length. Cover every dot.</p>
 <nav onClickCapture={e=>{if(frozen){e.preventDefault();e.stopPropagation();}}}><select aria-label="Tier" value={state.tier} onChange={e=>api.load(catalog({tier:e.target.value})[0].puzzleId)}>{['small','medium','large','extraordinary'].map(t=><option key={t}>{t}</option>)}</select>
 <select aria-label="Puzzle" value={state.puzzleId} onChange={e=>api.load(e.target.value)}>{catalog({tier:state.tier}).map(p=><option key={p.puzzleId} value={p.puzzleId}>{p.name} · {p.puzzleId}</option>)}</select>
 <button onClick={()=>{const pack=catalog({tier:state.tier});api.load(pack[Math.floor(Math.random()*pack.length)].puzzleId);}}>Random</button><button onClick={()=>api.load(state.puzzleId)}>Restart</button></nav>
 <div id="board" role="group" aria-label={`${state.name}, ${state.rows} rows by ${state.columns} columns`} onPointerDown={down} onPointerMove={move} onPointerUp={up} onPointerCancel={()=>{if(!frozen&&session.state().interaction.dragging)api.action('exit');pointer=null;}}>
 {state.cells.map((row,r)=><div className="row" key={r}>{row.map(cell=><div className="cell" data-cell={cell.id} data-line={cell.lineId??''} data-completed={cell.completed} key={cell.id}><Tile cell={cell} size={size} successAnimValue={{interpolate:({outputRange})=>outputRange[0]}}/></div>)}</div>)}</div>
 <div id="status" aria-live="polite">{state.cleared?'Puzzle complete!':`${state.lines.filter(l=>l.completed).length} / ${state.lines.length} lines complete`}</div>
 <p>Drag from a number or line end. Tap a number to reset its line.</p>
 {window.ordinaryPuzzlesStorage&&<p><button onClick={()=>api.checkpoint().catch(e=>setError('Progress storage stopped: '+e.message))}>Checkpoint</button><button onClick={()=>api.exit().then(()=>setError('Saved and released.')).catch(e=>setError('Progress storage stopped: '+e.message))}>{window.ordinaryPuzzlesStorage?.onExit?'Save & Return':'Save & Release'}</button></p>}
 <p role="alert">{error}</p>
 <details><summary>Local snapshot tools</summary><button onClick={()=>setJson(JSON.stringify(api.snapshot()))}>Export snapshot</button><textarea aria-label="Snapshot JSON" value={json} onChange={e=>setJson(e.target.value)}/><button onClick={()=>{try{api.restore(JSON.parse(json));setError('');}catch(e){setError(e.message);}}}>Restore snapshot</button><p className="error">{error}</p></details>
 </main>;
}
createRoot(document.getElementById('root')).render(<App/>);
