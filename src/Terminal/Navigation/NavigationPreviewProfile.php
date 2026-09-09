<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * The inputs that decide how a navigation definition looks and what it exposes
 * for one preview render: terminal geometry, charset, colour, capability
 * profile, locale/border style, and the viewer's access facts.
 *
 * A profile carries no socket and no session — it is the whole configuration a
 * caller needs to get a deterministic preview out of {@see NavigationPreviewService}.
 */
final class NavigationPreviewProfile
{
    public const GEOMETRIES = [
        '80x24'  => [80, 24],
        '132x36' => [132, 36],
        '132x51' => [132, 51],
    ];

    /**
     * @param array<string,bool> $features   feature name => enabled
     * @param array<string,bool> $capabilities color/utf8/sixel
     */
    public function __construct(
        public readonly int $cols = 80,
        public readonly int $rows = 24,
        public readonly string $charset = 'utf8',
        public readonly bool $color = true,
        public readonly bool $authenticated = true,
        public readonly bool $admin = false,
        public readonly bool $guest = false,
        public readonly string $locale = 'en',
        public readonly string $borderStyle = 'classic',
        public readonly array $features = [],
        public readonly array $capabilities = [],
    ) {
    }

    public static function geometry(string $key): self
    {
        [$c, $r] = self::GEOMETRIES[$key] ?? [80, 24];

        return new self(cols: $c, rows: $r);
    }

    public function with(array $overrides): self
    {
        return new self(
            $overrides['cols'] ?? $this->cols,
            $overrides['rows'] ?? $this->rows,
            $overrides['charset'] ?? $this->charset,
            $overrides['color'] ?? $this->color,
            $overrides['authenticated'] ?? $this->authenticated,
            $overrides['admin'] ?? $this->admin,
            $overrides['guest'] ?? $this->guest,
            $overrides['locale'] ?? $this->locale,
            $overrides['borderStyle'] ?? $this->borderStyle,
            $overrides['features'] ?? $this->features,
            $overrides['capabilities'] ?? $this->capabilities,
        );
    }

    /**
     * Turn on every feature named by an action-availability expression in the
     * registry, so a preview shows the definition's full item set unless the
     * caller overrides specific features off.
     */
    public function withAllFeaturesEnabled(ActionRegistry $registry): self
    {
        $features = $this->features;
        foreach ($registry->all() as $action) {
            preg_match_all('/feature:([A-Za-z0-9_]+)/', $action->availability->describe(), $m);
            foreach ($m[1] as $name) {
                $features[$name] ??= true;
            }
        }

        return $this->with(['features' => $features]);
    }
}
