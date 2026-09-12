/**
 * Terminal-side transport for the shared `LeasedClient` (Slice 4): spawns
 * `persistence/helper.php` once per terminal run with `DOOR_USER_NUMBER` set
 * to the trusted caller id (exactly the contract the sibling games'
 * NativeDoor-style CLI helpers already use — see
 * shared/dokuel/persistence/helper.php), and speaks its JSON-lines protocol
 * over stdin/stdout. No caller id is ever accepted from anywhere else; a
 * caller id must be validated by whatever launched this process (the
 * terminal daemon), matching the "trusted DOOR_USER_NUMBER" requirement.
 */
import { spawn, type ChildProcessWithoutNullStreams } from 'node:child_process';
import { createInterface } from 'node:readline';
import { fileURLToPath } from 'node:url';
import type { Request } from '../../persistence/client';

const HELPER_PATH = fileURLToPath(new URL('../../persistence/helper.php', import.meta.url));

export interface TerminalPersistenceHandle {
  request: Request;
  close: () => void;
}

/**
 * `doorUserNumber` must already be an authenticated, validated caller id —
 * this function does not check it; `helper.php` re-validates it against
 * `users.is_active` itself (defence in depth, matching every sibling game).
 */
export function openTerminalPersistence(
  doorUserNumber: number,
  phpBinary = 'php',
): TerminalPersistenceHandle {
  if (!Number.isInteger(doorUserNumber) || doorUserNumber <= 0) {
    throw new Error('parlour terminal persistence: a positive integer caller id is required');
  }
  const child: ChildProcessWithoutNullStreams = spawn(phpBinary, [HELPER_PATH], {
    env: { ...process.env, DOOR_USER_NUMBER: String(doorUserNumber) },
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const rl = createInterface({ input: child.stdout });
  const pending: Array<(line: string) => void> = [];
  rl.on('line', (line) => {
    const resolve = pending.shift();
    resolve?.(line);
  });
  let stderr = '';
  child.stderr.on('data', (chunk) => {
    stderr += chunk.toString();
  });

  const request: Request = (body) =>
    new Promise((resolve, reject) => {
      if (child.exitCode !== null) {
        reject(new Error(`parlour terminal persistence: helper exited (${stderr.slice(0, 200)})`));
        return;
      }
      pending.push((line) => {
        try {
          resolve(JSON.parse(line));
        } catch {
          reject(new Error('parlour terminal persistence: malformed helper response'));
        }
      });
      child.stdin.write(JSON.stringify(body) + '\n');
    });

  return {
    request,
    close: () => {
      rl.close();
      child.stdin.end();
      child.kill();
    },
  };
}
