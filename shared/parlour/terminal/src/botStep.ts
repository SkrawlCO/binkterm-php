/**
 * Bot-turn orchestration now lives in `facade/bots.ts` (shared with the
 * persistence envelope layer added in Slice 4 — bot continuity across a
 * save/restore is the same `drainBotTurns` run again after `fromSnapshot`).
 * Re-exported here so existing terminal imports keep working unchanged.
 */
export { stepBot, drainBotTurns, type BotStepResult } from '../../facade/bots';
