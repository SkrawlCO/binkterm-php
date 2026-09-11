import { winPercentage } from '../dist/upstream/src/storage/statsRepository.js';
const MARK = {correct:'=',present:'+',absent:'-',empty:'.',filled:'.'};
const COLOR = {correct:32,present:33,absent:90,empty:37,filled:37};
export const plain = text => text.replace(/\x1b\[[0-9;?]*[A-Za-z]/g,'');
const safe = value => String(value).replace(/[^\x20-\x7e]/g,'?');
const duration = ms => ms === null ? '--' : `${(ms/1000).toFixed(1)}s`;

/** Input and presentation only. Session is the sole game/stat owner. */
export class TerminalAdapter {
    constructor(session, {color=true}={}) {
        this.session=session; this.color=color; this.screen='game'; this.page=0; this.message='';
    }
    handle(text, key={}) {
        if(key.ctrl && key.name==='c') return 'quit';
        if(key.name==='escape') {this.screen=this.screen==='game'?'menu':'game';return;}
        if(this.screen==='menu') {
            switch(text?.toUpperCase()) {
                case 'N': this.session.newGame(); this.screen='game'; this.message='New game started.'; break;
                case 'L': this.screen='length'; break;
                case 'S': this.screen='stats'; this.page=0; break;
                case '?': this.screen='help'; break;
                case 'Q': return 'quit';
            }
            return;
        }
        if(this.screen==='length') {
            if(['4','5','6'].includes(text)) {this.session.setLength(Number(text));this.screen='game';this.message='Length selected.';}
            return;
        }
        if(this.screen==='stats') {
            const pages=Math.max(1,Math.ceil(this.session.getStats().recentGames.length/5));
            if(text===']'||key.name==='right')this.page=Math.min(pages-1,this.page+1);
            if(text==='['||key.name==='left')this.page=Math.max(0,this.page-1);
            return;
        }
        if(this.screen==='help') return;
        if(text==='?'){this.screen='help';return;}
        if(key.ctrl||key.meta) return;
        if(key.name==='return'||key.name==='enter') {
            const result=this.session.submit();
            if(result.ok) {this.session.completeReveal();this.message='';}
            else this.message=({'too-short':'Not enough letters.','not-a-word':'Not in word list.',
                'not-playing':'Round complete. Esc, then N starts another game.','rejected':'Guess not accepted.'})[result.error];
        } else if(key.name==='backspace') {this.session.backspace();this.message='';}
        else if(/^[a-z]$/i.test(text??'')) {this.session.inputLetter(text);this.message='';}
    }
    tile(letter,state) {
        const content=`${letter||'_'}${MARK[state]}`;
        return this.color ? `\x1b[${COLOR[state]}m${content}\x1b[0m` : content;
    }
    render() {
        const s=this.session.getState(); const lines=[];
        lines.push('                         WORDWRIGHT',`                 Unlimited play | ${s.wordLength} letters`,'');
        if(this.screen==='game') {
            for(const row of this.session.getBoardProjection())
                lines.push('                 '+row.tiles.map(t=>this.tile(t.letter,t.state)).join('  '));
            lines.push('', '         = correct position   + elsewhere   - absent   . input');
            const keys=this.session.getKeyboardState();
            for(const letters of ['ABCDEFGHIJKLM','NOPQRSTUVWXYZ'])
                lines.push('         '+[...letters].map(l=>this.tile(l,keys.get(l)??'empty')).join(' '));
            lines.push('',`         Guess: ${s.currentInput||'_'}   |   Attempts left: ${this.session.getRemainingAttempts()}`);
            lines.push(s.status==='won'?'         SOLVED! Esc, then N for another game.':s.status==='lost'
                ?`         Answer: ${s.answer}. Esc, then N to play again.`:'         Type letters; Enter submits; Backspace erases.');
            lines.push('         Esc commands | ? help | Ctrl-C quit','', '         '+this.message);
        } else if(this.screen==='menu') {
            lines.push('         COMMANDS','', '         N  New game (discards current game)',
                '         L  Choose 4 / 5 / 6 letters','         S  Statistics and recent games',
                '         ?  Help','         Q  Quit / return','', '         Esc resumes this game.',
                '', '         During play, N/L/S/Q are ordinary guess letters.');
        } else if(this.screen==='length') {
            lines.push('         CHOOSE LENGTH','','         4  Four letters','         5  Five letters','         6  Six letters',
                '', '         Changing length starts a new game.','         Esc cancels.');
        } else if(this.screen==='help') {
            lines.push('         HOW TO PLAY','','         Guess the hidden word. Feedback comes from Wordwright.',
                '         = correct position | + elsewhere | - absent',
                '         Feedback symbols also work without colour.','',
                '         All A-Z keys type letters, including N, L, S and Q.',
                '         Enter submits; Backspace erases.',
                '         Reveal completes immediately through the shared engine.',
                '', '         Esc opens commands; Esc again resumes.',
                '         Stats: [ / ] or arrows browse recent games.',
                '         Ctrl-C quits cleanly. Esc returns to play.');
        } else {
            const stats=this.session.getStats(), b=stats.overall;
            lines.push(`         Played ${b.gamesPlayed} | Won ${b.gamesWon} | Win ${winPercentage(b)}%`,
                `         Streak ${b.currentStreak} | Best ${b.bestStreak} | Score ${b.totalScore}`,
                `         Winning time ${duration(b.totalSolveMs)} | Fastest ${duration(b.fastestSolveMs)}`,
                '         Distribution: '+b.distribution.map((n,i)=>`${i+1}:${n}`).join('  '),'',
                '         Length        Played      Won      Streak');
            for(const length of [4,5,6]) {const b=stats.perLength[length];lines.push(`         ${length} letters      ${b.gamesPlayed}           ${b.gamesWon}          ${b.currentStreak}`);}
            lines.push('',`         RECENT GAMES | Page ${this.page+1}/${Math.max(1,Math.ceil(stats.recentGames.length/5))}`,
                '         Answer   Len Result Guesses  Time       Score');
            for(const r of stats.recentGames.slice(this.page*5,this.page*5+5))
                lines.push(`         ${r.answer.padEnd(8)} ${r.wordLength}   ${r.won?'WIN ':'LOSS'}   ${r.guessesUsed}    ${duration(r.solveMs).padEnd(10)} ${r.score}`);
            if(!stats.recentGames.length)lines.push('         No completed games yet.');
            lines.push('', '         [ previous | ] next | Esc returns to play');
        }
        // Presentation clipping only: no puzzle state or actions are filtered here.
        return lines.map(line=>plain(line).length<=78?line:safe(plain(line)).slice(0,78)).slice(0,23).join('\r\n');
    }
    snapshot(){return this.session.snapshot();}
    prepareHandoff(){return this.session.prepareHandoff();}
}
