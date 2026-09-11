/**
 * Joins class names, dropping falsy values.
 *
 * Deliberately tiny: we do not need `clsx` or `tailwind-merge` as runtime
 * dependencies (NFR-10). Conditional classes are written as
 * `cn('base', isActive && 'active')`.
 */
export function cn(...values: Array<string | false | null | undefined>): string {
  return values.filter(Boolean).join(' ');
}
