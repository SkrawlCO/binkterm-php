import type { AssistLevel } from "../lib/types.ts";
import { SlidingRadioGroup } from "./SlidingRadioGroup.tsx";

const OPTIONS: { value: AssistLevel; label: string; description: string }[] = [
  { value: "paper", label: "Paper", description: "No hints" },
  { value: "standard", label: "Standard", description: "Auto-clear notes" },
  { value: "full", label: "Full", description: "Counts + more" },
];

type AssistLevelPickerProps = {
  value: AssistLevel;
  onChange: (level: AssistLevel) => void;
};

export function AssistLevelPicker({ value, onChange }: AssistLevelPickerProps) {
  return (
    <SlidingRadioGroup
      options={OPTIONS}
      value={value}
      onChange={onChange}
      name="assist-level"
      ariaLabel="Assistance level"
    />
  );
}
