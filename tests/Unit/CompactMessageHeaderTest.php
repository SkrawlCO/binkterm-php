<?php

declare(strict_types=1);

use BinktermPHP\TelnetServer\TelnetUtils;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../telnet/src/OutputSink.php';
require_once __DIR__ . '/../../telnet/src/SocketSink.php';
require_once __DIR__ . '/../../telnet/src/BufferSink.php';
require_once __DIR__ . '/../../telnet/src/GlyphPolicy.php';
require_once __DIR__ . '/../../telnet/src/TerminalCapabilities.php';
require_once __DIR__ . '/../../telnet/src/TerminalRenderContext.php';
require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';

/**
 * Compact terminal message-reader header (TelnetUtils::buildCompactMessageHeader).
 *
 * The reader engine (TelnetUtils::runMessageViewer) derives its body viewport
 * purely from count($headerLines): shrinking the header from the seven-row
 * buildMessageHeaderBox() to a three-row compact header is what hands the body
 * four more rows. These tests pin the row budget, the field hierarchy, the
 * 80-column width discipline in every charset, and the sanitisation contract.
 */
final class CompactMessageHeaderTest extends TestCase
{
    private const WIDTH = 78; // 80-col terminal, viewer uses $cols - 2

    protected function setUp(): void
    {
        TelnetUtils::setAnsiColorEnabled(true);
    }

    protected function tearDown(): void
    {
        TelnetUtils::setAnsiColorEnabled(true);
    }

    /** Visible width of a line after stripping SGR sequences. */
    private static function visibleWidth(string $line): int
    {
        $plain = preg_replace('/\033\[[0-9;]*m/', '', $line) ?? $line;
        return mb_strlen($plain, 'UTF-8');
    }

    private static function stripSgr(string $line): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $line) ?? $line;
    }

    private function echomailFields(): array
    {
        return [
            'from'         => 'Matthew Munroe',
            'from_address' => '21:1/158',
            'to'           => 'All',
            'area'         => 'SN_SOCIETY@spooknet',
            'date'         => 'Wed 14:32',
            'subject'      => 'Re: welcome to the board',
        ];
    }

    public function testHeaderIsExactlyThreeRowsAndReclaimsBodySpace(): void
    {
        foreach (['utf8', 'cp437', 'ascii'] as $charset) {
            $lines = TelnetUtils::buildCompactMessageHeader(self::WIDTH, $this->echomailFields(), $charset);
            $this->assertCount(3, $lines, "compact header must be 3 rows ({$charset})");
        }

        // runMessageViewer body math: rows - headerCount - 1
        $compactBody = 24 - 3 - 1;                // new
        $oldBoxBody  = 24 - 7 - 1;                // buildMessageHeaderBox(): 5 fields + 2 borders
        $this->assertSame(20, $compactBody);
        $this->assertGreaterThanOrEqual($oldBoxBody + 4, $compactBody, 'must reclaim >= 4 body rows');
    }

    public function testEveryLineStaysWithinEightyColumnsInEveryCharset(): void
    {
        foreach (['utf8', 'cp437', 'ascii'] as $charset) {
            $lines = TelnetUtils::buildCompactMessageHeader(self::WIDTH, $this->echomailFields(), $charset);
            foreach ($lines as $i => $line) {
                $this->assertLessThanOrEqual(
                    self::WIDTH,
                    self::visibleWidth($line),
                    "row {$i} exceeds width in {$charset}"
                );
            }
        }
    }

    public function testFieldHierarchyIsRepresented(): void
    {
        $lines = TelnetUtils::buildCompactMessageHeader(self::WIDTH, $this->echomailFields(), 'utf8');
        $context = self::stripSgr($lines[0]);
        $subject = self::stripSgr($lines[1]);

        // 1. Subject: strong, on its own line.
        $this->assertStringStartsWith('Subject: ', $subject);
        $this->assertStringContainsString('Re: welcome to the board', $subject);

        // 2. From identity + address immediately visible.
        $this->assertStringContainsString('Matthew Munroe', $context);
        $this->assertStringContainsString('21:1/158', $context);

        // 3. To / Area / Date represented without dominating.
        $this->assertStringContainsString('All', $context);
        $this->assertStringContainsString('SN_SOCIETY@spooknet', $context);
        $this->assertStringContainsString('Wed 14:32', $context);

        // 3rd row is a full-width rule.
        $this->assertSame(str_repeat("\u{2500}", self::WIDTH), self::stripSgr($lines[2]));
    }

    public function testLongMetadataClipsWithoutEllipsisOrOverflowInCp437(): void
    {
        $fields = [
            'from'         => str_repeat('Bartholomew ', 6) . 'Wigglesworth-Featherstonehaugh',
            'from_address' => '999:9999/9999.9999',
            'to'           => str_repeat('Recipient ', 8),
            'area'         => str_repeat('VERY_LONG_AREA_TAG.', 5) . '@somenet',
            'date'         => 'Wed 14:32',
            'subject'      => str_repeat('A rather verbose subject line that will not fit ', 4),
        ];

        $lines = TelnetUtils::buildCompactMessageHeader(self::WIDTH, $fields, 'cp437');

        $this->assertCount(3, $lines);
        foreach ($lines as $i => $line) {
            $this->assertLessThanOrEqual(self::WIDTH, self::visibleWidth($line), "row {$i} overflows");
            // No U+2026 and no CP437 transliteration of it.
            $this->assertStringNotContainsString("\u{2026}", $line);
        }
        // Subject label + the start of the subject still survive the clip.
        $this->assertStringStartsWith('Subject: A rather verbose', self::stripSgr($lines[1]));
        // From name still leads the identity line.
        $this->assertStringStartsWith('Bartholomew', self::stripSgr($lines[0]));
    }

    public function testSubjectSurvivesEvenWhenIdentityLineIsFull(): void
    {
        $fields = $this->echomailFields();
        $fields['from'] = str_repeat('X', 200);
        $lines = TelnetUtils::buildCompactMessageHeader(self::WIDTH, $fields, 'ascii');
        $this->assertStringStartsWith('Subject: Re: welcome to the board', self::stripSgr($lines[1]));
        $this->assertLessThanOrEqual(self::WIDTH, self::visibleWidth($lines[0]));
    }

    public function testControlSequencesInMetadataAreStripped(): void
    {
        $fields = [
            'from'         => "Evil\033[31mUser\007",
            'from_address' => "21:1/1\033[2J",
            'to'           => "All\033[H",
            'area'         => "AREA\033[5m",
            'date'         => 'Wed 14:32',
            'subject'      => "Totally\033[1;41m fine\007 subject",
        ];

        $lines = TelnetUtils::buildCompactMessageHeader(self::WIDTH, $fields, 'utf8');
        $joined = implode("\n", $lines);

        // The only ESC bytes allowed are our own SGR colour runs (\033[...m).
        $withoutSgr = preg_replace('/\033\[[0-9;]*m/', '', $joined) ?? $joined;
        $this->assertStringNotContainsString("\033", $withoutSgr, 'no raw escape sequences survive');
        $this->assertStringNotContainsString("\007", $joined, 'no BEL survives');
        $this->assertStringContainsString('EvilUser', self::stripSgr($lines[0]));
        $this->assertStringContainsString('Totally fine subject', self::stripSgr($lines[1]));
    }

    public function testAnsiDisabledReturnsPlainRows(): void
    {
        TelnetUtils::setAnsiColorEnabled(false);
        $lines = TelnetUtils::buildCompactMessageHeader(self::WIDTH, $this->echomailFields(), 'cp437');

        $this->assertCount(3, $lines);
        foreach ($lines as $line) {
            $this->assertStringNotContainsString("\033", $line, 'plain mode emits no SGR');
        }
        $this->assertStringStartsWith('Subject: ', $lines[1]);
    }

    public function testNetmailShapeDegradesCleanlyWithoutAreaOrTo(): void
    {
        // Inbox netmail: principal label + sender, no area, no separate "to".
        $inbox = TelnetUtils::buildCompactMessageHeader(self::WIDTH, [
            'principal_label' => 'From: ',
            'from'            => 'Kludge Corvid',
            'from_address'    => '21:2/100',
            'date'            => '2026-09-10 14:32',
            'subject'         => 'your packet arrived',
        ], 'utf8');

        $this->assertCount(3, $inbox);
        $context = self::stripSgr($inbox[0]);
        $this->assertStringStartsWith('From: Kludge Corvid', $context);
        $this->assertStringContainsString('21:2/100', $context);
        $this->assertStringContainsString('2026-09-10 14:32', $context);
        $this->assertStringNotContainsString("\u{2192}", $context, 'no arrow when there is no To');
        $this->assertStringStartsWith('Subject: your packet arrived', self::stripSgr($inbox[1]));

        // Sent netmail: principal label flips to "To: ".
        $sent = TelnetUtils::buildCompactMessageHeader(self::WIDTH, [
            'principal_label' => 'To: ',
            'from'            => 'Distant Sysop',
            'from_address'    => '3:770/1',
            'date'            => '2026-09-10 14:32',
            'subject'         => 'ping',
        ], 'ascii');
        $this->assertStringStartsWith('To: Distant Sysop', self::stripSgr($sent[0]));
    }

    public function testMissingFieldsFallBackWithoutError(): void
    {
        $lines = TelnetUtils::buildCompactMessageHeader(self::WIDTH, [], 'utf8');
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('Unknown', self::stripSgr($lines[0]));
        $this->assertStringStartsWith('Subject: Message', self::stripSgr($lines[1]));
    }

    public function testNarrowWidthIsClampedAndStillThreeRows(): void
    {
        $lines = TelnetUtils::buildCompactMessageHeader(10, $this->echomailFields(), 'utf8');
        $this->assertCount(3, $lines);
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(20, self::visibleWidth($line));
        }
    }
}
