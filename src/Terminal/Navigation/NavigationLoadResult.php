<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * The outcome of loading a declarative navigation definition: either a usable
 * {@see NavigationDefinition}, or a list of {@see ValidationError}s explaining
 * why it was rejected. Never both.
 */
final class NavigationLoadResult
{
    /**
     * @param array<int,ValidationError> $errors
     */
    private function __construct(
        private readonly ?NavigationDefinition $definition,
        private readonly array $errors,
    ) {
    }

    public static function ok(NavigationDefinition $definition): self
    {
        return new self($definition, []);
    }

    /**
     * @param array<int,ValidationError> $errors
     */
    public static function failed(array $errors): self
    {
        return new self(null, array_values($errors));
    }

    public function isOk(): bool
    {
        return $this->definition !== null;
    }

    public function definition(): ?NavigationDefinition
    {
        return $this->definition;
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
