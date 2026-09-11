import type { ButtonHTMLAttributes, ReactNode } from 'react';

import { cn } from '@/lib/cn';

export type ButtonVariant = 'primary' | 'secondary' | 'danger';

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  readonly variant?: ButtonVariant;
  readonly children: ReactNode;
}

const VARIANT_CLASS: Record<ButtonVariant, string> = {
  primary: 'bg-tile-correct text-tile-text-revealed hover:opacity-90',
  secondary: 'border-2 border-border-strong bg-transparent text-text hover:bg-surface-raised',
  danger: 'bg-danger text-danger-text hover:opacity-90',
};

export function Button({
  variant = 'primary',
  className,
  children,
  type = 'button',
  ...rest
}: ButtonProps): React.JSX.Element {
  return (
    <button
      type={type}
      className={cn(
        'inline-flex min-h-11 items-center justify-center rounded px-4 py-2',
        'text-sm font-semibold tracking-wide uppercase',
        'transition-opacity disabled:cursor-not-allowed disabled:opacity-50',
        VARIANT_CLASS[variant],
        className,
      )}
      {...rest}
    >
      {children}
    </button>
  );
}
