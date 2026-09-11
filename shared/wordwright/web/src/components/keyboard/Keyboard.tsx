import { memo } from 'react';

import type { LetterState } from '@/engine';

import { Key, type KeyValue } from './Key';

const ROWS: readonly (readonly KeyValue[])[] = [
  ['Q', 'W', 'E', 'R', 'T', 'Y', 'U', 'I', 'O', 'P'],
  ['A', 'S', 'D', 'F', 'G', 'H', 'J', 'K', 'L'],
  ['ENTER', 'Z', 'X', 'C', 'V', 'B', 'N', 'M', 'BACKSPACE'],
];

export interface KeyboardProps {
  readonly keyStates: ReadonlyMap<string, LetterState>;
  readonly disabled: boolean;
  readonly onKeyPress: (value: KeyValue) => void;
}

export const Keyboard = memo(function Keyboard({
  keyStates,
  disabled,
  onKeyPress,
}: KeyboardProps): React.JSX.Element {
  return (
    <div aria-label="Keyboard" className="flex w-full max-w-lg flex-col gap-1.5 px-0.5">
      {ROWS.map((row, index) => (
        <div key={index} className="flex justify-center gap-1 sm:gap-1.5">
          {/* The middle row is inset on both sides, as on a real keyboard. */}
          {index === 1 ? <div className="w-1 shrink sm:w-2" aria-hidden="true" /> : null}
          {row.map((value) => (
            <Key
              key={value}
              value={value}
              state={keyStates.get(value)}
              disabled={disabled}
              onPress={onKeyPress}
            />
          ))}
          {index === 1 ? <div className="w-1 shrink sm:w-2" aria-hidden="true" /> : null}
        </div>
      ))}
    </div>
  );
});
