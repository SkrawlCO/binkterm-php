// Adapted from pinned GameProvider: presentation context only; shared session owns all transitions.
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { isGameOver, type GameState, type LetterState, type RowView, type WordLength } from '@/engine';
import { describeEvaluation } from '@/lib/format';
import { act, useSnapshot, projection, keyboard, remaining, storageStatus } from '../bridge';
import { useSettings, useToast } from './contexts';
import { GameContext } from './gameContexts';
export type DictionaryStatus='idle'|'loading'|'ready'|'error';
export interface GameContextValue {
 readonly state:GameState; readonly rows:readonly RowView[]; readonly keyStates:ReadonlyMap<string,LetterState>;
 readonly dictionaryStatus:DictionaryStatus; readonly wordLength:WordLength; readonly isRevealing:boolean;
 readonly isGameOver:boolean; readonly announcement:string;
 readonly addLetter:(letter:string)=>void; readonly removeLetter:()=>void; readonly submitGuess:()=>boolean;
 readonly completeReveal:()=>void; readonly restart:()=>void; readonly setWordLength:(length:WordLength)=>void;
 readonly retryDictionary:()=>void;
}
export function GameProvider({children}:{children:ReactNode}) {
 const snapshot=useSnapshot(); const state=snapshot.game;
 const {show}=useToast(); const {setDefaultWordLength}=useSettings();
 const [announcement,setAnnouncement]=useState('');
 const announced=useRef('');
 useEffect(()=>{
   const key=state.id+':'+state.status+':'+state.guesses.length;
   if(key===announced.current) return;
   announced.current=key;
   if(isGameOver(state)) setAnnouncement(state.status==='won'
     ? `You won in ${state.guesses.length} ${state.guesses.length===1?'guess':'guesses'}.`
     : `Game over. The word was ${state.answer}.`);
   else if(state.status==='playing' && state.guesses.length){
     const last=state.guesses[state.guesses.length-1]; const left=remaining();
     setAnnouncement(`${last.word}: ${describeEvaluation(last.word,last.evaluation)}. ${left} ${left===1?'guess':'guesses'} remaining.`);
   } else if(!state.guesses.length) setAnnouncement('New game started.');
 },[state]);
 const addLetter=useCallback((letter:string)=>{act(s=>s.inputLetter(letter));},[]);
 const removeLetter=useCallback(()=>{act(s=>s.backspace());},[]);
 const submitGuess=useCallback(()=>{
   const result=act(s=>s.submit());
   if(!result.ok && (result.error==='too-short'||result.error==='not-a-word'))
     show(result.error==='too-short'?'Not enough letters':'Not in word list','error');
   return result.ok;
 },[show]);
 const completeReveal=useCallback(()=>{act(s=>s.completeReveal());},[]);
 const restart=useCallback(()=>{act(s=>s.newGame());},[]);
 const setWordLength=useCallback((length:WordLength)=>{act(s=>s.setLength(length));setDefaultWordLength(length);},[setDefaultWordLength]);
 return <GameContext.Provider value={{state,rows:projection(),keyStates:keyboard(),dictionaryStatus:storageStatus().active?'ready':'loading',wordLength:snapshot.selectedLength,
 isRevealing:state.status==='revealing',isGameOver:isGameOver(state),announcement,
 addLetter,removeLetter,submitGuess,completeReveal,restart,setWordLength,retryDictionary:()=>{}}}>{children}</GameContext.Provider>;
}
