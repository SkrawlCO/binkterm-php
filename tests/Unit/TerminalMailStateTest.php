<?php

declare(strict_types=1);

use BinktermPHP\Terminal\TerminalMailState;
use BinktermPHP\UserMeta;
use PHPUnit\Framework\TestCase;

/**
 * {@see TerminalMailState} is the extracted canonical owner of per-user terminal
 * browser state; the `GET|POST /api/user/terminal-mail-state` routes are now thin
 * adapters over it and the terminal handlers call it directly. These tests pin
 * the validation contract the POST route used to enforce inline — value rules,
 * exact error strings, and the order fields are checked in (so the "first
 * invalid value" reported for a multi-bad payload is unchanged).
 */
final class TerminalMailStateTest extends TestCase
{
    private FakeUserMeta $meta;
    private TerminalMailState $state;

    protected function setUp(): void
    {
        $this->meta  = new FakeUserMeta();
        $this->state = new TerminalMailState($this->meta);
    }

    public function testLoadReturnsEveryTerminalKeyInRouteOrder(): void
    {
        $this->meta->store['7:terminal_netmail_folder'] = 'sent';
        $this->meta->store['7:terminal_echomail_sort']  = 'subject';

        self::assertSame([
            'terminal_netmail_page',
            'terminal_netmail_selected_message_id',
            'terminal_netmail_folder',
            'terminal_netmail_sort',
            'terminal_echomail_areas_page',
            'terminal_echomail_positions',
            'terminal_echomail_sort',
            'terminal_chat_target',
        ], array_keys($this->state->load(7)));

        $loaded = $this->state->load(7);
        self::assertSame('sent', $loaded['terminal_netmail_folder']);
        self::assertSame('subject', $loaded['terminal_echomail_sort']);
        self::assertNull($loaded['terminal_netmail_page']);
    }

    public function testIntegerKeysAcceptPositivesAndClearOnEmpty(): void
    {
        self::assertSame(['ok' => true, 'error' => null], $this->state->save(1, [
            'terminal_netmail_page'               => 4,
            'terminal_echomail_areas_page'        => '3',
            'terminal_netmail_selected_message_id' => null,
        ]));

        self::assertSame('4', $this->meta->store['1:terminal_netmail_page']);
        self::assertSame('3', $this->meta->store['1:terminal_echomail_areas_page']);
        self::assertNull($this->meta->store['1:terminal_netmail_selected_message_id']);
    }

    public function testIntegerKeyRejectsZeroAndNonNumericWithTheRouteErrorString(): void
    {
        self::assertSame(
            ['ok' => false, 'error' => 'Invalid value for terminal_netmail_page'],
            $this->state->save(1, ['terminal_netmail_page' => 0])
        );
        self::assertSame(
            ['ok' => false, 'error' => 'Invalid value for terminal_echomail_areas_page'],
            $this->state->save(1, ['terminal_echomail_areas_page' => 'abc'])
        );
    }

    public function testPositionsAreCleanedAndReencoded(): void
    {
        $res = $this->state->save(1, [
            'terminal_echomail_positions' => [
                'GENERAL@fidonet' => ['page' => 2, 'selected_message_id' => 55],
                'BAD@area'        => ['page' => -3, 'selected_message_id' => 0],
                'notanarray'      => 'x',
                str_repeat('x', 200) . '@long' => ['page' => 1],
            ],
        ]);

        self::assertSame(['ok' => true, 'error' => null], $res);
        $decoded = json_decode($this->meta->store['1:terminal_echomail_positions'], true);
        self::assertSame(['page' => 2, 'selected_message_id' => 55], $decoded['GENERAL@fidonet']);
        self::assertSame(['page' => 1, 'selected_message_id' => null], $decoded['BAD@area']);
        self::assertArrayNotHasKey('notanarray', $decoded);
        self::assertCount(2, $decoded, 'the >128-char area key is dropped');
    }

    public function testPositionsAcceptAJsonStringAndRejectNonArrays(): void
    {
        self::assertSame(['ok' => true, 'error' => null], $this->state->save(1, [
            'terminal_echomail_positions' => '{"A@b":{"page":3}}',
        ]));
        self::assertSame(
            ['ok' => false, 'error' => 'Invalid value for terminal_echomail_positions'],
            $this->state->save(1, ['terminal_echomail_positions' => 'not json'])
        );
        self::assertSame(
            ['ok' => false, 'error' => 'Invalid value for terminal_echomail_positions'],
            $this->state->save(1, ['terminal_echomail_positions' => 5])
        );
    }

    public function testPositionsOverLimitAreRejected(): void
    {
        $big = [];
        for ($i = 0; $i < 4000; $i++) {
            $big['AREA' . $i . '@dom'] = ['page' => 1, 'selected_message_id' => 999999];
        }
        self::assertSame(
            ['ok' => false, 'error' => 'Invalid value for terminal_echomail_positions'],
            $this->state->save(1, ['terminal_echomail_positions' => $big])
        );
    }

    /** @dataProvider sortKeys */
    public function testSortKeysWhitelist(string $key): void
    {
        self::assertSame(['ok' => true, 'error' => null], $this->state->save(1, [$key => 'author']));
        self::assertSame('author', $this->meta->store["1:{$key}"]);

        self::assertSame(['ok' => true, 'error' => null], $this->state->save(1, [$key => '']));
        self::assertNull($this->meta->store["1:{$key}"]);

        self::assertSame(
            ['ok' => false, 'error' => "Invalid value for {$key}"],
            $this->state->save(1, [$key => 'sideways'])
        );
    }

    public static function sortKeys(): array
    {
        return [['terminal_echomail_sort'], ['terminal_netmail_sort']];
    }

    public function testFolderWhitelist(): void
    {
        self::assertSame(['ok' => true, 'error' => null], $this->state->save(1, ['terminal_netmail_folder' => 'sent']));
        self::assertSame('sent', $this->meta->store['1:terminal_netmail_folder']);
        self::assertSame(
            ['ok' => false, 'error' => 'Invalid value for terminal_netmail_folder'],
            $this->state->save(1, ['terminal_netmail_folder' => 'archive'])
        );
    }

    public function testChatTargetShapeIsValidated(): void
    {
        self::assertSame(['ok' => true, 'error' => null], $this->state->save(1, [
            'terminal_chat_target' => ['type' => 'dm', 'id' => 12, 'label' => 'Someone'],
        ]));
        self::assertSame(
            '{"type":"dm","id":12,"label":"Someone"}',
            $this->meta->store['1:terminal_chat_target']
        );

        foreach ([
            ['type' => 'group', 'id' => 1, 'label' => 'x'],
            ['type' => 'room', 'id' => 0, 'label' => 'x'],
            ['type' => 'room', 'id' => 1, 'label' => ''],
        ] as $bad) {
            self::assertSame(
                ['ok' => false, 'error' => 'Invalid value for terminal_chat_target'],
                $this->state->save(1, ['terminal_chat_target' => $bad])
            );
        }
    }

    public function testFirstInvalidValueMatchesTheRouteFieldOrder(): void
    {
        // Route checks folder BEFORE netmail_sort — a payload bad in both reports folder.
        self::assertSame(
            ['ok' => false, 'error' => 'Invalid value for terminal_netmail_folder'],
            $this->state->save(1, [
                'terminal_netmail_folder' => 'nope',
                'terminal_netmail_sort'   => 'nope',
            ])
        );
        // And int keys are checked before everything else.
        self::assertSame(
            ['ok' => false, 'error' => 'Invalid value for terminal_netmail_page'],
            $this->state->save(1, [
                'terminal_netmail_page'   => -1,
                'terminal_netmail_folder' => 'nope',
            ])
        );
    }

    public function testRoundTrips(): void
    {
        $this->state->save(9, [
            'terminal_echomail_areas_page' => 5,
            'terminal_netmail_folder'      => 'sent',
            'terminal_echomail_sort'       => 'author',
            'terminal_echomail_positions'  => ['X@y' => ['page' => 7, 'selected_message_id' => 3]],
        ]);

        $loaded = $this->state->load(9);
        self::assertSame('5', $loaded['terminal_echomail_areas_page']);
        self::assertSame('sent', $loaded['terminal_netmail_folder']);
        self::assertSame('author', $loaded['terminal_echomail_sort']);
        self::assertSame(
            ['X@y' => ['page' => 7, 'selected_message_id' => 3]],
            json_decode($loaded['terminal_echomail_positions'], true)
        );
    }

    public function testUnknownKeysAreIgnored(): void
    {
        self::assertSame(['ok' => true, 'error' => null], $this->state->save(1, [
            'not_a_terminal_key' => 'whatever',
            'terminal_web_page'  => 3,
        ]));
        self::assertSame([], $this->meta->store);
    }
}

final class FakeUserMeta extends UserMeta
{
    /** @var array<string,?string> */
    public array $store = [];

    // phpcs:ignore -- deliberately skip the parent DB-connecting constructor
    public function __construct()
    {
    }

    public function getValue(int $userId, string $key): ?string
    {
        return $this->store["{$userId}:{$key}"] ?? null;
    }

    public function setValue(int $userId, string $key, ?string $value): void
    {
        $this->store["{$userId}:{$key}"] = $value;
    }
}
