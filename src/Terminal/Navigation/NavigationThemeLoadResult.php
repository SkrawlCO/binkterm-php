<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * The outcome of resolving the optional terminal presentation theme.
 *
 * Three states, never overlapping:
 *   - ABSENT  — no theme file configured. Not an error; the zero-config default
 *               is the flowing declarative renderer.
 *   - INVALID — a theme file exists but does not parse or validate. Surfaced to
 *               the sysop (logged live, shown in the F6 preview) and the
 *               fallback renderer is used.
 *   - OK      — a usable {@see NavigationTheme}. It may still be disabled
 *               (`enabled: false`), in which case the fallback is used.
 */
final class NavigationThemeLoadResult
{
    private const ABSENT  = 'absent';
    private const INVALID = 'invalid';
    private const OK      = 'ok';

    /**
     * @param array<int,ValidationError> $errors
     */
    private function __construct(
        private readonly string $status,
        private readonly ?NavigationTheme $theme,
        private readonly array $errors,
    ) {
    }

    public static function absent(): self
    {
        return new self(self::ABSENT, null, []);
    }

    /** @param array<int,ValidationError> $errors */
    public static function invalid(array $errors): self
    {
        return new self(self::INVALID, null, array_values($errors));
    }

    public static function ok(NavigationTheme $theme): self
    {
        return new self(self::OK, $theme, []);
    }

    public function isAbsent(): bool
    {
        return $this->status === self::ABSENT;
    }

    public function isInvalid(): bool
    {
        return $this->status === self::INVALID;
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }

    public function theme(): ?NavigationTheme
    {
        return $this->theme;
    }

    /** @return array<int,ValidationError> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function errorSummary(): string
    {
        return implode('; ', array_map(static fn (ValidationError $e) => (string) $e, $this->errors));
    }
}
