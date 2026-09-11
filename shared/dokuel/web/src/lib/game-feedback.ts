import { haptics } from "./haptics.ts";
import { sounds } from "./sounds.ts";

export type GameFeedback = {
  onPlace: () => void;
  onErase: () => void;
  onToggleNotes: () => void;
  onHint: () => void;
  onConflict: () => void;
  onComplete: () => void;
};

export const gameFeedback: GameFeedback = {
  onPlace: () => {
    haptics.tap();
    sounds.place();
  },
  onErase: () => {
    haptics.tap();
    sounds.erase();
  },
  onToggleNotes: () => {
    haptics.light();
    sounds.note();
  },
  onHint: () => {
    haptics.tap();
  },
  onConflict: () => {
    haptics.conflict();
    sounds.conflict();
  },
  onComplete: () => {
    haptics.success();
    sounds.complete();
  },
};
