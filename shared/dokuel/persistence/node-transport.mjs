import { spawn } from 'node:child_process';
import { createInterface } from 'node:readline';
import { fileURLToPath } from 'node:url';

/** Only a trusted local launcher supplies DOOR_USER_NUMBER; never read caller ID from keys. */
export function helperTransport(command = 'php', args = [fileURLToPath(new URL('./helper.php', import.meta.url))], options = {}) {
    const child = spawn(command, args, { ...options, stdio: ['pipe', 'pipe', 'pipe'] });
    const waiting = []; let dead = false;
    const fail = () => { dead = true; for (const item of waiting.splice(0)) item.reject(new Error('Storage helper closed')); };
    child.on('error', fail); child.on('exit', fail); child.stdin.on('error', fail);
    child.stderr.resume();
    createInterface({ input: child.stdout }).on('line', line => {
        const pending = waiting.shift(); if (!pending) return fail();
        try { pending.resolve(JSON.parse(line)); } catch (error) { pending.reject(error); }
    });
    return {
        request: data => new Promise((resolve, reject) => {
            if (dead) return reject(new Error('Storage helper closed'));
            waiting.push({ resolve, reject });
            child.stdin.write(JSON.stringify(data) + '\n');
        }),
        close: () => child.stdin.end(),
    };
}
