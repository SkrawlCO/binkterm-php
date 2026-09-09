<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * A fully-resolved menu entry ready to render: label text already translated,
 * access already decided (hidden items are absent from the screen model), and
 * disabled state already computed. Carries no behaviour.
 */
final class NavigationScreenItem
{
    public const KIND_ACTION  = 'action';
    public const KIND_SUBMENU = 'submenu';

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly ?string $description,
        public readonly ?string $hotkey,
        public readonly string $emphasis,
        public readonly bool $enabled,
        public readonly ?string $disabledReason,
        public readonly string $kind,
        public readonly ?string $targetNodeId,
        public readonly ?ActionReference $action,
        public readonly ?string $glyph = null,
        public readonly ?string $group = null,
    ) {
    }

    public function isSubmenu(): bool
    {
        return $this->kind === self::KIND_SUBMENU;
    }

    public function isSelectable(): bool
    {
        return $this->enabled;
    }
}
