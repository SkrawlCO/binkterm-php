<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * One problem found in a declarative navigation definition, tied to the exact
 * place it occurs so a sysop can fix it.
 */
final class ValidationError
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly string $path = '',
    ) {
    }

    public function __toString(): string
    {
        return $this->path === ''
            ? "[{$this->code}] {$this->message}"
            : "[{$this->code}] {$this->path}: {$this->message}";
    }
}
