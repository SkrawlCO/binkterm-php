<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * A navigation screen: an ordered list of {@see NavigationItem}s plus its own
 * label / help / access / presentation. Nodes are addressed by id and may be
 * referenced as submenu destinations from items on other nodes.
 */
final class NavigationNode
{
    /**
     * @param array<int,NavigationItem> $items
     */
    public function __construct(
        public readonly string $id,
        public readonly string $labelKey,
        public readonly string $labelFallback,
        public readonly ?string $descriptionKey,
        public readonly ?string $descriptionFallback,
        public readonly array $items,
        public readonly AccessExpression $access,
        public readonly PresentationHints $presentation,
    ) {
    }

    /**
     * @param array<string,mixed> $raw
     */
    public static function fromConfig(array $raw, string $path): self
    {
        $allowed = [
            'id', 'label', 'label_key', 'label_fallback', 'description', 'description_key',
            'description_fallback', 'items', 'access', 'presentation',
        ];
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new NavigationSchemaException("unknown node field \"{$key}\"", $path);
            }
        }

        $id = trim((string) ($raw['id'] ?? ''));
        if ($id === '') {
            throw new NavigationSchemaException('node.id is required', "{$path}.id");
        }

        [$labelKey, $labelFallback] = self::label($raw, "{$path}.label", true);
        [$descKey, $descFallback]   = self::label($raw, "{$path}.description", false, 'description');

        if (!isset($raw['items']) || !is_array($raw['items']) || !array_is_list($raw['items'])) {
            throw new NavigationSchemaException('node.items must be an array', "{$path}.items");
        }

        $items = [];
        foreach ($raw['items'] as $i => $itemRaw) {
            if (!is_array($itemRaw)) {
                throw new NavigationSchemaException('item must be an object', "{$path}.items[{$i}]");
            }
            $items[] = NavigationItem::fromConfig($itemRaw, "{$path}.items[{$i}]");
        }

        return new self(
            $id,
            $labelKey,
            $labelFallback,
            $descKey,
            $descFallback,
            $items,
            AccessExpression::fromConfig($raw['access'] ?? null, "{$path}.access"),
            PresentationHints::fromConfig($raw['presentation'] ?? null, "{$path}.presentation"),
        );
    }

    /** @return array{0:string,1:string}|array{0:null,1:null} */
    private static function label(array $raw, string $path, bool $required, string $field = 'label'): array
    {
        $obj      = $raw[$field] ?? null;
        $flatKey  = $raw["{$field}_key"] ?? null;
        $flatBack = $raw["{$field}_fallback"] ?? null;

        if (is_array($obj)) {
            $key      = isset($obj['key']) ? (string) $obj['key'] : '';
            $fallback = isset($obj['fallback']) ? (string) $obj['fallback'] : '';
        } else {
            $key      = $flatKey !== null ? (string) $flatKey : '';
            $fallback = $flatBack !== null ? (string) $flatBack : (is_string($obj) ? $obj : '');
        }

        $key      = trim($key);
        $fallback = trim($fallback);

        if ($key === '' && $fallback === '') {
            if ($required) {
                throw new NavigationSchemaException("a {$field} (key and/or fallback) is required", $path);
            }

            return [null, null];
        }
        if ($fallback === '') {
            throw new NavigationSchemaException("{$field} needs a literal fallback string", $path);
        }

        return [$key, $fallback];
    }
}
