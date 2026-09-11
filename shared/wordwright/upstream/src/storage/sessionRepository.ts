import { RECENT_ANSWER_WINDOW } from '@/engine/constants';
import type { GameState, WordLength } from '@/engine/types';

import { createRepository, type Repository, type StorageIssue } from './createRepository';
import { STORAGE_KEYS } from './keys';
import { defaultMeta, validateMeta, validateSession, type StorageMeta } from './schemas';

/**
 * The in-progress game (FR-32, EC-9, EC-10).
 *
 * Stored as the raw `GameState`, so a restore is a straight assignment with no
 * reconstruction step. A session saved mid-reveal is normalised to `playing`
 * on read: the guess is already recorded and evaluated, so the correct
 * behaviour after a refresh is to show that row revealed and let play continue
 * (EC-10).
 */
export function createSessionRepository(
  onIssue?: (issue: StorageIssue, key: string) => void,
): Repository<GameState | null> {
  return createRepository<GameState | null>({
    key: STORAGE_KEYS.session,
    defaults: () => null,
    validate: (value) => {
      if (value === null) return null;

      const session = validateSession(value);
      if (!session) return null;

      // `revealing` is a transient animation state that cannot survive a
      // reload; resolve it to a stable one.
      if (session.status === 'revealing') {
        return { ...session, status: 'playing' };
      }

      // `loading`/`idle`/`error` describe app startup, not a real game.
      if (session.status === 'loading' || session.status === 'idle' || session.status === 'error') {
        return null;
      }

      return session;
    },
    ...(onIssue ? { onIssue } : {}),
  });
}

/**
 * True when a restored session is still usable (FR-33).
 *
 * The answer must still exist in the current dictionary: regenerating the word
 * lists can retire a word, and resuming a game whose solution is no longer
 * valid would be unwinnable.
 */
export function isSessionUsable(
  session: GameState | null,
  wordLength: WordLength,
  isKnownAnswer: (word: string) => boolean,
): session is GameState {
  if (!session) return false;
  if (session.wordLength !== wordLength) return false;
  if (!isKnownAnswer(session.answer)) return false;

  return true;
}

/* ------------------------------------------------------------------ *
 * Meta: schema version + the recent-answer ring buffer (FR-4)
 * ------------------------------------------------------------------ */

export function createMetaRepository(
  onIssue?: (issue: StorageIssue, key: string) => void,
): Repository<StorageMeta> {
  return createRepository<StorageMeta>({
    key: STORAGE_KEYS.meta,
    defaults: defaultMeta,
    validate: validateMeta,
    ...(onIssue ? { onIssue } : {}),
  });
}

/** Pushes an answer onto the front of its length's ring buffer (FR-4). */
export function rememberAnswer(
  meta: StorageMeta,
  wordLength: WordLength,
  answer: string,
): StorageMeta {
  const previous = meta.recentAnswers[wordLength];

  return {
    ...meta,
    recentAnswers: {
      ...meta.recentAnswers,
      [wordLength]: [answer, ...previous.filter((word) => word !== answer)].slice(
        0,
        RECENT_ANSWER_WINDOW,
      ),
    },
  };
}
