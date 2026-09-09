<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * A small, safe, declarative access-control expression.
 *
 * This is NOT `eval`, not a SQL fragment, and not an arbitrary function call. An
 * expression is one of:
 *
 *   - a bare predicate string: `"authenticated"`, `"guest"`, `"admin"`,
 *     `"feature:webdoors"`, `"action:doors"`, `"capability:color"`, `"always"`;
 *   - `{"all": [ <expr>, ... ]}`  — logical AND (empty list = allow);
 *   - `{"any": [ <expr>, ... ]}`  — logical OR  (empty list = deny);
 *   - `{"not": <expr>}`           — logical NOT.
 *
 * Composition may be nested arbitrarily. Anything else is a hard schema error
 * ({@see NavigationSchemaException}); the caller decides whether that fails the
 * whole definition or just that node. Evaluation is total and deterministic and
 * an unknown predicate evaluates to `false` (fail closed).
 */
final class AccessExpression
{
    public const KIND_ALWAYS     = 'always';
    private const OP_ALL = 'all';
    private const OP_ANY = 'any';
    private const OP_NOT = 'not';

    /** Predicate names that take no argument. */
    private const BARE_PREDICATES = ['always', 'authenticated', 'guest', 'admin', 'sysop'];

    /** `prefix:` predicates that take an argument. */
    private const ARG_PREDICATES = ['feature', 'action', 'capability', 'env'];

    /**
     * @param string                   $type      'predicate' | 'all' | 'any' | 'not'
     * @param string                   $predicate predicate name (for 'predicate')
     * @param string|null              $argument  predicate argument (for 'predicate')
     * @param array<int,AccessExpression> $children operands (for 'all'/'any'/'not')
     */
    private function __construct(
        private readonly string $type,
        private readonly string $predicate = '',
        private readonly ?string $argument = null,
        private readonly array $children = [],
    ) {
    }

    /** An expression that always allows (the default when a definition omits access). */
    public static function always(): self
    {
        return new self('predicate', self::KIND_ALWAYS);
    }

    /**
     * Parse an expression from decoded config (string or array). Throws on any
     * structure that is not part of the documented grammar.
     *
     * @param mixed  $raw
     */
    public static function fromConfig(mixed $raw, string $path = 'access'): self
    {
        if ($raw === null) {
            return self::always();
        }

        if (is_string($raw)) {
            return self::parsePredicate($raw, $path);
        }

        if (!is_array($raw)) {
            throw new NavigationSchemaException('access expression must be a string or an object', $path);
        }

        $keys = array_keys($raw);
        if (count($keys) !== 1 || !in_array($keys[0], [self::OP_ALL, self::OP_ANY, self::OP_NOT], true)) {
            throw new NavigationSchemaException(
                'access object must have exactly one of "all", "any", "not"',
                $path
            );
        }

        $op      = $keys[0];
        $operand = $raw[$op];

        if ($op === self::OP_NOT) {
            return new self(self::OP_NOT, children: [self::fromConfig($operand, "{$path}.not")]);
        }

        if (!is_array($operand) || !array_is_list($operand)) {
            throw new NavigationSchemaException("\"{$op}\" must be an array of expressions", $path);
        }

        $children = [];
        foreach ($operand as $i => $child) {
            $children[] = self::fromConfig($child, "{$path}.{$op}[{$i}]");
        }

        return new self($op, children: $children);
    }

    private static function parsePredicate(string $raw, string $path): self
    {
        $token = trim($raw);
        if ($token === '') {
            throw new NavigationSchemaException('empty access predicate', $path);
        }

        if (str_contains($token, ':')) {
            [$name, $arg] = explode(':', $token, 2);
            $name = strtolower(trim($name));
            $arg  = trim($arg);
            if (!in_array($name, self::ARG_PREDICATES, true)) {
                throw new NavigationSchemaException("unknown access predicate \"{$name}\"", $path);
            }
            if ($arg === '') {
                throw new NavigationSchemaException("access predicate \"{$name}:\" needs an argument", $path);
            }

            return new self('predicate', $name, $arg);
        }

        $name = strtolower($token);
        if (!in_array($name, self::BARE_PREDICATES, true)) {
            throw new NavigationSchemaException("unknown access predicate \"{$token}\"", $path);
        }

        return new self('predicate', $name);
    }

    /** Deterministically evaluate the expression. Unknown predicates fail closed. */
    public function evaluate(AccessContext $ctx): bool
    {
        return match ($this->type) {
            self::OP_ALL => $this->evaluateAll($ctx),
            self::OP_ANY => $this->evaluateAny($ctx),
            self::OP_NOT => !$this->children[0]->evaluate($ctx),
            default      => $this->evaluatePredicate($ctx),
        };
    }

    private function evaluateAll(AccessContext $ctx): bool
    {
        foreach ($this->children as $child) {
            if (!$child->evaluate($ctx)) {
                return false;
            }
        }

        return true;
    }

    private function evaluateAny(AccessContext $ctx): bool
    {
        foreach ($this->children as $child) {
            if ($child->evaluate($ctx)) {
                return true;
            }
        }

        return false;
    }

    private function evaluatePredicate(AccessContext $ctx): bool
    {
        return match ($this->predicate) {
            self::KIND_ALWAYS => true,
            'authenticated'   => $ctx->isAuthenticated(),
            'guest'           => $ctx->isGuest(),
            'admin', 'sysop'  => $ctx->isAdmin(),
            'feature'         => $ctx->featureEnabled((string) $this->argument),
            'action'          => $ctx->actionAvailable((string) $this->argument),
            'capability'      => $ctx->hasCapability((string) $this->argument),
            'env'             => self::envTruthy((string) $this->argument),
            default           => false,
        };
    }

    private static function envTruthy(string $var): bool
    {
        $value = \BinktermPHP\Config::env($var);

        return $value !== null
            && $value !== ''
            && $value !== '0'
            && strtolower((string) $value) !== 'false'
            && strtolower((string) $value) !== 'no';
    }

    /** Referenced action ids anywhere in this expression (for validation). */
    public function referencedActionIds(): array
    {
        if ($this->type === 'predicate') {
            return $this->predicate === 'action' && $this->argument !== null ? [$this->argument] : [];
        }
        $ids = [];
        foreach ($this->children as $child) {
            $ids = array_merge($ids, $child->referencedActionIds());
        }

        return array_values(array_unique($ids));
    }

    /** A stable, human-readable form (for diagnostics and previews). */
    public function describe(): string
    {
        return match ($this->type) {
            self::OP_ALL => '(' . implode(' AND ', array_map(fn ($c) => $c->describe(), $this->children)) . ')',
            self::OP_ANY => '(' . implode(' OR ', array_map(fn ($c) => $c->describe(), $this->children)) . ')',
            self::OP_NOT => 'NOT ' . $this->children[0]->describe(),
            default      => $this->argument !== null ? "{$this->predicate}:{$this->argument}" : $this->predicate,
        };
    }
}
