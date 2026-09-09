<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * The caller's position in the navigation tree: the ordered list of nodes from
 * the root down to the node currently shown. Renderers use it to communicate
 * orientation (where am I, what is Back / Home) in whatever form suits the
 * terminal — a breadcrumb path, a context line, a title prefix.
 */
final class NavigationPath
{
    /**
     * @param array<int,array{id:string,label:string}> $segments
     */
    private function __construct(private readonly array $segments)
    {
    }

    public static function root(string $nodeId, string $label): self
    {
        return new self([['id' => $nodeId, 'label' => $label]]);
    }

    public function push(string $nodeId, string $label): self
    {
        return new self([...$this->segments, ['id' => $nodeId, 'label' => $label]]);
    }

    public function pop(): self
    {
        if (count($this->segments) <= 1) {
            return $this;
        }

        return new self(array_slice($this->segments, 0, -1));
    }

    public function toRoot(): self
    {
        return new self([$this->segments[0]]);
    }

    public function depth(): int
    {
        return count($this->segments);
    }

    public function isRoot(): bool
    {
        return count($this->segments) <= 1;
    }

    public function currentId(): string
    {
        return $this->segments[array_key_last($this->segments)]['id'];
    }

    public function parentId(): ?string
    {
        $n = count($this->segments);

        return $n >= 2 ? $this->segments[$n - 2]['id'] : null;
    }

    public function rootId(): string
    {
        return $this->segments[0]['id'];
    }

    /** @return array<int,string> */
    public function labels(): array
    {
        return array_map(static fn (array $s) => $s['label'], $this->segments);
    }

    /** @return array<int,array{id:string,label:string}> */
    public function segments(): array
    {
        return $this->segments;
    }

    /** A compact "A » B » C" style rendering of the path labels. */
    public function crumb(string $separator = ' > '): string
    {
        return implode($separator, $this->labels());
    }
}
