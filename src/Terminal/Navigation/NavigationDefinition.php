<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * A complete, parsed declarative navigation tree: a schema version, an id, the
 * id of the root node, and a map of every {@see NavigationNode} keyed by id.
 *
 * This is a pure in-memory model — it holds no sockets, no session, no services,
 * and no rendered bytes. Building one never touches the database or the network.
 * Structural parsing lives here; semantic validation (unknown actions, hotkey
 * conflicts, unreachable nodes, …) is {@see NavigationValidator}'s job.
 */
final class NavigationDefinition
{
    public const CURRENT_SCHEMA = 1;

    /** Reasonableness ceilings — a real navigation tree is nowhere near these. */
    public const MAX_NODES = 500;
    public const MAX_ITEMS_PER_NODE = 200;

    /**
     * @param array<string,NavigationNode> $nodes
     */
    private function __construct(
        public readonly int $schema,
        public readonly string $id,
        public readonly string $rootId,
        public readonly array $nodes,
        public readonly string $sourceLabel,
    ) {
    }

    public function root(): NavigationNode
    {
        return $this->nodes[$this->rootId];
    }

    public function hasNode(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    public function node(string $id): NavigationNode
    {
        if (!isset($this->nodes[$id])) {
            throw new NavigationSchemaException("no such node \"{$id}\"");
        }

        return $this->nodes[$id];
    }

    /** @return array<string,NavigationNode> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /**
     * Parse (but do not semantically validate) a decoded definition array.
     *
     * @param array<string,mixed> $raw
     * @throws NavigationSchemaException on any structural problem
     */
    public static function fromArray(array $raw, string $sourceLabel = 'definition'): self
    {
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, ['schema', 'id', 'root', 'nodes'], true)) {
                throw new NavigationSchemaException("unknown top-level field \"{$key}\"");
            }
        }

        $schema = $raw['schema'] ?? null;
        if (!is_int($schema)) {
            throw new NavigationSchemaException('"schema" (integer) is required', 'schema');
        }
        if ($schema < 1 || $schema > self::CURRENT_SCHEMA) {
            throw new NavigationSchemaException(
                "unsupported schema version {$schema} (this build understands 1.." . self::CURRENT_SCHEMA . ')',
                'schema'
            );
        }

        $id = trim((string) ($raw['id'] ?? ''));
        if ($id === '') {
            throw new NavigationSchemaException('"id" is required', 'id');
        }

        $rootId = trim((string) ($raw['root'] ?? ''));
        if ($rootId === '') {
            throw new NavigationSchemaException('"root" node id is required', 'root');
        }

        if (!isset($raw['nodes']) || !is_array($raw['nodes']) || !array_is_list($raw['nodes']) || $raw['nodes'] === []) {
            throw new NavigationSchemaException('"nodes" must be a non-empty array', 'nodes');
        }
        if (count($raw['nodes']) > self::MAX_NODES) {
            throw new NavigationSchemaException(
                'too many nodes (' . count($raw['nodes']) . '; limit ' . self::MAX_NODES . ')',
                'nodes'
            );
        }

        $nodes = [];
        foreach ($raw['nodes'] as $i => $nodeRaw) {
            if (!is_array($nodeRaw)) {
                throw new NavigationSchemaException('node must be an object', "nodes[{$i}]");
            }
            if (is_array($nodeRaw['items'] ?? null) && count($nodeRaw['items']) > self::MAX_ITEMS_PER_NODE) {
                throw new NavigationSchemaException(
                    'too many items (' . count($nodeRaw['items']) . '; limit ' . self::MAX_ITEMS_PER_NODE . ')',
                    "nodes[{$i}].items"
                );
            }
            $node = NavigationNode::fromConfig($nodeRaw, "nodes[{$i}]");
            if (isset($nodes[$node->id])) {
                throw new NavigationSchemaException("duplicate node id \"{$node->id}\"", "nodes[{$i}]");
            }
            $nodes[$node->id] = $node;
        }

        if (!isset($nodes[$rootId])) {
            throw new NavigationSchemaException("root node \"{$rootId}\" is not defined in \"nodes\"", 'root');
        }

        return new self($schema, $id, $rootId, $nodes, $sourceLabel);
    }
}
