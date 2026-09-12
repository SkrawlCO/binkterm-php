#!/usr/bin/env node
/**
 * Terminal entrypoint. Sets raw mode, starts the shell, and guarantees
 * terminal-mode/cursor/alt-screen restoration on every exit path (normal
 * quit, Ctrl-C, SIGTERM) — no stray output, no leftover raw mode.
 *
 * Production (NativeDoor) use: the door-launch bridge sets `DOOR_USER_NUMBER`
 * on this process's environment before spawning it (the same trusted
 * mechanism `shared/dokuel`/`shared/breaklock`'s own terminal doors already
 * use — see `persistence.ts`'s own doc comment). When present, every move
 * checkpoints to the caller's real `parlour`/slot-0 storage and a clean
 * quit (or Ctrl-C/SIGTERM) releases the lease. Without it (local `tsx`
 * runs, dev/testing), play stays exactly as ephemeral as Slice 3 proved.
 */
import { ParlourShell } from './shell';
import { openTerminalPersistence } from './persistence';
import { LeasedClient } from '../../persistence/client';

const noColor = process.argv.includes('--no-color') || process.env.NO_COLOR === '1';

function trustedDoorUserNumber(): number | null {
  const raw = process.env.DOOR_USER_NUMBER;
  if (!raw || !/^[1-9][0-9]{0,9}$/.test(raw)) return null;
  return Number(raw);
}

function main(): void {
  const stdin = process.stdin;
  const wasRaw = stdin.isTTY ? stdin.isRaw : undefined;
  if (stdin.isTTY) stdin.setRawMode(true);
  stdin.resume();
  stdin.setEncoding('utf8');

  const doorUserNumber = trustedDoorUserNumber();
  let closePersistence: (() => void) | null = null;
  let lease: LeasedClient | null = null;
  if (doorUserNumber !== null) {
    const handle = openTerminalPersistence(doorUserNumber);
    closePersistence = handle.close;
    lease = new LeasedClient(handle.request);
  }

  const shell = new ParlourShell({
    input: stdin,
    output: process.stdout,
    color: !noColor,
    lease,
    onExit: () => {
      closePersistence?.();
      if (stdin.isTTY && wasRaw !== undefined) stdin.setRawMode(wasRaw);
      process.stdout.write('\r\n');
      process.exit(0);
    },
  });

  const cleanExit = () => void shell.stop();
  process.on('SIGINT', cleanExit);
  process.on('SIGTERM', cleanExit);

  void shell.start();
}

main();
