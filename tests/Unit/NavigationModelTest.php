<?php

declare(strict_types=1);

use BinktermPHP\Terminal\Navigation\AccessContext;
use BinktermPHP\Terminal\Navigation\AccessExpression;
use BinktermPHP\Terminal\Navigation\ActionRegistry;
use BinktermPHP\Terminal\Navigation\DefaultNavigationDefinition;
use BinktermPHP\Terminal\Navigation\NavigationDefinition;
use BinktermPHP\Terminal\Navigation\NavigationDefinitionLoader;
use BinktermPHP\Terminal\Navigation\NavigationSchemaException;
use BinktermPHP\Terminal\Navigation\TerminalAction;
use BinktermPHP\Terminal\Navigation\TerminalActionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * R5A + R5C + R5D + R5H — declarative navigation model, action registry,
 * access expressions, and validator.
 */
final class NavigationModelTest extends TestCase
{
    // ===== schema / parsing =====

    public function testMinimalDefinitionParses(): void
    {
        $def = NavigationDefinition::fromArray([
            'schema' => 1,
            'id'     => 'demo',
            'root'   => 'home',
            'nodes'  => [
                ['id' => 'home', 'label_fallback' => 'Home', 'items' => [
                    ['id' => 'a', 'label_fallback' => 'Messages', 'action' => 'echomail'],
                ]],
            ],
        ]);

        self::assertSame(1, $def->schema);
        self::assertSame('home', $def->rootId);
        self::assertSame('Messages', $def->root()->items[0]->labelFallback);
        self::assertTrue($def->root()->items[0]->isAction());
    }

    /** @dataProvider malformedProvider */
    public function testMalformedDefinitionsThrowWithAPath(array $raw, string $expectFragment): void
    {
        try {
            NavigationDefinition::fromArray($raw);
            self::fail('expected NavigationSchemaException');
        } catch (NavigationSchemaException $e) {
            self::assertStringContainsString($expectFragment, $e->getMessage());
        }
    }

    public static function malformedProvider(): array
    {
        $base = fn (array $over) => array_merge([
            'schema' => 1, 'id' => 'x', 'root' => 'm',
            'nodes' => [['id' => 'm', 'label_fallback' => 'M', 'items' => [
                ['id' => 'i', 'label_fallback' => 'I', 'action' => 'echomail'],
            ]]],
        ], $over);

        return [
            'no schema'        => [['id' => 'x', 'root' => 'm', 'nodes' => []], 'schema'],
            'future schema'    => [$base(['schema' => 99]), 'unsupported schema'],
            'no id'            => [['schema' => 1, 'root' => 'm', 'nodes' => []], '"id" is required'],
            'root missing'     => [$base(['root' => 'ghost']), 'root node "ghost" is not defined'],
            'unknown field'    => [$base(['bogus' => 1]), 'unknown top-level field'],
            'dup node'         => [[
                'schema' => 1, 'id' => 'x', 'root' => 'm', 'nodes' => [
                    ['id' => 'm', 'label_fallback' => 'M', 'items' => [['id' => 'a', 'label_fallback' => 'A', 'action' => 'echomail']]],
                    ['id' => 'm', 'label_fallback' => 'M2', 'items' => [['id' => 'b', 'label_fallback' => 'B', 'action' => 'echomail']]],
                ],
            ], 'duplicate node id'],
            'item both action+submenu' => [[
                'schema' => 1, 'id' => 'x', 'root' => 'm', 'nodes' => [
                    ['id' => 'm', 'label_fallback' => 'M', 'items' => [
                        ['id' => 'a', 'label_fallback' => 'A', 'action' => 'echomail', 'submenu' => 'm'],
                    ]],
                ],
            ], 'exactly one of "action" or "submenu"'],
            'item neither'     => [[
                'schema' => 1, 'id' => 'x', 'root' => 'm', 'nodes' => [
                    ['id' => 'm', 'label_fallback' => 'M', 'items' => [['id' => 'a', 'label_fallback' => 'A']]],
                ],
            ], 'exactly one of "action" or "submenu"'],
            'no fallback label' => [[
                'schema' => 1, 'id' => 'x', 'root' => 'm', 'nodes' => [
                    ['id' => 'm', 'label_fallback' => 'M', 'items' => [
                        ['id' => 'a', 'label_key' => 'ui.x', 'action' => 'echomail'],
                    ]],
                ],
            ], 'needs a literal fallback'],
            'bad hotkey'       => [[
                'schema' => 1, 'id' => 'x', 'root' => 'm', 'nodes' => [
                    ['id' => 'm', 'label_fallback' => 'M', 'items' => [
                        ['id' => 'a', 'label_fallback' => 'A', 'hotkey' => 'ab', 'action' => 'echomail'],
                    ]],
                ],
            ], 'single character'],
        ];
    }

    public function testLabelObjectFormAndFlatFormAreEquivalent(): void
    {
        $obj = NavigationDefinition::fromArray([
            'schema' => 1, 'id' => 'x', 'root' => 'm', 'nodes' => [
                ['id' => 'm', 'label' => ['key' => 'ui.m', 'fallback' => 'Menu'], 'items' => [
                    ['id' => 'a', 'label' => ['key' => 'ui.a', 'fallback' => 'Alpha'], 'action' => 'echomail'],
                ]],
            ],
        ]);
        self::assertSame('ui.m', $obj->root()->labelKey);
        self::assertSame('Menu', $obj->root()->labelFallback);
        self::assertSame('ui.a', $obj->root()->items[0]->labelKey);
    }

    // ===== access expressions =====

    /** @dataProvider accessProvider */
    public function testAccessExpressionTruthTable($raw, array $ctxArgs, bool $expected): void
    {
        $ctx = new AccessContext(
            $ctxArgs['auth'] ?? true,
            $ctxArgs['admin'] ?? false,
            $ctxArgs['guest'] ?? false,
            fn (string $f) => in_array($f, $ctxArgs['features'] ?? [], true),
            fn (string $a) => in_array($a, $ctxArgs['actions'] ?? [], true),
            $ctxArgs['caps'] ?? [],
        );

        self::assertSame($expected, AccessExpression::fromConfig($raw)->evaluate($ctx));
    }

    public static function accessProvider(): array
    {
        return [
            'always'                 => ['always', [], true],
            'authenticated when auth' => ['authenticated', ['auth' => true], true],
            'authenticated when guest' => ['authenticated', ['auth' => false, 'guest' => true], false],
            'guest predicate'         => ['guest', ['auth' => false, 'guest' => true], true],
            'admin false for user'    => ['admin', ['admin' => false], false],
            'sysop alias'             => ['sysop', ['admin' => true], true],
            'feature present'         => ['feature:webdoors', ['features' => ['webdoors']], true],
            'feature absent'          => ['feature:webdoors', ['features' => []], false],
            'action present'          => ['action:doors', ['actions' => ['doors']], true],
            'capability present'      => ['capability:color', ['caps' => ['color' => true]], true],
            'capability absent'       => ['capability:sixel', ['caps' => ['color' => true]], false],
            'AND all true'            => [['all' => ['authenticated', 'feature:webdoors']], ['features' => ['webdoors']], true],
            'AND one false'           => [['all' => ['authenticated', 'feature:qwk']], ['features' => []], false],
            'AND empty allows'        => [['all' => []], [], true],
            'OR one true'             => [['any' => ['admin', 'feature:shoutbox']], ['features' => ['shoutbox']], true],
            'OR none true'            => [['any' => ['admin', 'feature:shoutbox']], [], false],
            'OR empty denies'         => [['any' => []], [], false],
            'NOT inverts'             => [['not' => 'guest'], ['guest' => false], true],
            'nested'                  => [['all' => ['authenticated', ['any' => ['admin', ['not' => 'guest']]]]], ['guest' => false], true],
            'unknown predicate fails closed via env' => ['env:__NAV_TEST_UNSET__', [], false],
        ];
    }

    /** @dataProvider invalidAccessProvider */
    public function testInvalidAccessExpressionsAreRejected($raw, string $fragment): void
    {
        $this->expectException(NavigationSchemaException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($fragment, '/') . '/');
        AccessExpression::fromConfig($raw);
    }

    public static function invalidAccessProvider(): array
    {
        return [
            'two keys'         => [['all' => ['x'], 'any' => ['y']], 'exactly one of'],
            'unknown op'       => [['maybe' => ['x']], 'exactly one of'],
            'bad predicate'    => ['definitely-not-real', 'unknown access predicate'],
            'arg predicate no arg' => ['feature:', 'needs an argument'],
            'unknown arg pred' => ['role:cosysop', 'unknown access predicate "role"'],
            'all not a list'   => [['all' => 'nope'], 'must be an array'],
            'number'           => [42, 'must be a string or an object'],
        ];
    }

    public function testAccessExpressionNeverCallsEvalOrArbitraryCode(): void
    {
        // A string that would be dangerous if eval'd is just an unknown predicate.
        $this->expectException(NavigationSchemaException::class);
        AccessExpression::fromConfig('system("rm -rf /")');
    }

    // ===== action registry =====

    public function testDefaultCatalogRegistersKnownActions(): void
    {
        $registry = TerminalActionCatalog::defaultRegistry();

        foreach (['newscan', 'netmail', 'echomail', 'doors', 'files', 'settings', 'quit'] as $id) {
            self::assertTrue($registry->has($id), "action {$id} registered");
        }
        self::assertFalse($registry->has('nuke_the_site'));
        self::assertFalse($registry->get('newscan')->terminates);
        self::assertTrue(
            $registry->isAvailable('newscan', new AccessContext(true, false, false, fn () => false, fn () => false, [])),
            'newscan only needs authentication'
        );
        self::assertTrue($registry->get('quit')->terminates);
        self::assertFalse($registry->get('netmail')->terminates);
    }

    public function testActionAvailabilityUsesTheDeclaredExpression(): void
    {
        $registry = TerminalActionCatalog::defaultRegistry();
        $withDoors = new AccessContext(true, false, false, fn ($f) => $f === 'webdoors', fn () => false, []);
        $withoutDoors = new AccessContext(true, false, false, fn () => false, fn () => false, []);

        self::assertTrue($registry->isAvailable('doors', $withDoors));
        self::assertFalse($registry->isAvailable('doors', $withoutDoors));
        self::assertTrue($registry->isAvailable('netmail', $withoutDoors), 'netmail only needs authentication');
    }

    public function testBindingIsSeparateFromMetadataAndConfigCannotSupplyIt(): void
    {
        $registry = TerminalActionCatalog::defaultRegistry();
        self::assertFalse($registry->isBound('echomail'));

        $calls = [];
        $registry->bind('echomail', function ($ctx, array $params) use (&$calls) {
            $calls[] = [$ctx, $params];
            return 'done';
        });

        self::assertTrue($registry->isBound('echomail'));
        self::assertSame('done', $registry->invoke('echomail', 'CTX', ['area' => 1]));
        self::assertSame([['CTX', ['area' => 1]]], $calls);
    }

    public function testCannotBindUnknownAction(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        (new ActionRegistry())->bind('ghost', fn () => null);
    }

    public function testInvokingUnboundActionThrows(): void
    {
        $this->expectException(\LogicException::class);
        TerminalActionCatalog::defaultRegistry()->invoke('netmail', null);
    }

    // ===== validator =====

    public function testDefaultDefinitionValidatesClean(): void
    {
        $result = DefaultNavigationDefinition::load();
        self::assertTrue($result->isOk(), $result->errorSummary());
        self::assertSame('main', $result->definition()->rootId);
    }

    public function testValidatorReportsHotkeyConflicts(): void
    {
        $result = $this->load([
            'nodes' => [['id' => 'main', 'label_fallback' => 'M', 'items' => [
                ['id' => 'a', 'label_fallback' => 'A', 'hotkey' => 'x', 'action' => 'netmail'],
                ['id' => 'b', 'label_fallback' => 'B', 'hotkey' => 'X', 'action' => 'echomail'],
            ]]],
        ]);

        self::assertFalse($result->isOk());
        self::assertContains('hotkey_conflict', array_map(fn ($e) => $e->code, $result->errors()));
    }

    public function testValidatorReportsUnknownActionsAndSubmenus(): void
    {
        $result = $this->load([
            'nodes' => [['id' => 'main', 'label_fallback' => 'M', 'items' => [
                ['id' => 'a', 'label_fallback' => 'A', 'action' => 'does_not_exist'],
                ['id' => 'b', 'label_fallback' => 'B', 'submenu' => 'missing'],
            ]]],
        ]);

        $codes = array_map(fn ($e) => $e->code, $result->errors());
        self::assertContains('unknown_action', $codes);
        self::assertContains('unknown_submenu', $codes);
    }

    public function testValidatorReportsCyclesAndUnreachableNodes(): void
    {
        $result = $this->load([
            'root'  => 'main',
            'nodes' => [
                ['id' => 'main', 'label_fallback' => 'M', 'items' => [
                    ['id' => 'toa', 'label_fallback' => 'A', 'submenu' => 'a'],
                ]],
                ['id' => 'a', 'label_fallback' => 'A', 'items' => [
                    ['id' => 'tob', 'label_fallback' => 'B', 'submenu' => 'b'],
                ]],
                ['id' => 'b', 'label_fallback' => 'B', 'items' => [
                    ['id' => 'toa', 'label_fallback' => 'A', 'submenu' => 'a'],
                ]],
                ['id' => 'island', 'label_fallback' => 'Island', 'items' => [
                    ['id' => 'x', 'label_fallback' => 'X', 'action' => 'netmail'],
                ]],
            ],
        ]);

        $codes = array_map(fn ($e) => $e->code, $result->errors());
        self::assertContains('cycle', $codes);
        self::assertContains('unreachable_node', $codes);
    }

    public function testValidatorAcceptsAValidNestedDefinition(): void
    {
        $result = $this->load([
            'root'  => 'main',
            'nodes' => [
                ['id' => 'main', 'label_fallback' => 'Main', 'items' => [
                    ['id' => 'msg', 'label_fallback' => 'Messages', 'hotkey' => 'm', 'submenu' => 'messages'],
                    ['id' => 'q', 'label_fallback' => 'Quit', 'hotkey' => 'q', 'action' => 'quit'],
                ]],
                ['id' => 'messages', 'label_fallback' => 'Messages', 'items' => [
                    ['id' => 'nm', 'label_fallback' => 'Netmail', 'hotkey' => 'n', 'action' => 'netmail'],
                    ['id' => 'em', 'label_fallback' => 'Echomail', 'hotkey' => 'e', 'action' => 'echomail'],
                ]],
            ],
        ]);

        self::assertTrue($result->isOk(), $result->errorSummary());
    }

    private function load(array $overrides): \BinktermPHP\Terminal\Navigation\NavigationLoadResult
    {
        $raw = array_merge(['schema' => 1, 'id' => 'test', 'root' => 'main'], $overrides);

        return (new NavigationDefinitionLoader(TerminalActionCatalog::defaultRegistry()))->fromArray($raw, 'test');
    }
}
