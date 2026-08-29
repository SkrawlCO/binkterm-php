<?php

declare(strict_types=1);

use BinktermPHP\TelnetServer\DoorHandler;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../telnet/src/DoorHandler.php';

/**
 * M4E telnet cursor-key compatibility.
 *
 * A native door's PTY runs under TERM=xterm-256color, whose ncurses terminfo
 * defines the cursor keys only in application (SS3) form (kcuf1=\EOC, ...) and
 * asks the terminal to switch via smkx (DECCKM). Classic BBS telnet clients
 * that cannot honour DECCKM keep sending the normal-mode CSI form
 * (ESC [ A/B/C/D), which the door's ncurses never matches -> the character
 * does not move.
 *
 * processRawTelnetInput() rewrites those four exact sequences to SS3 for
 * native doors only. RLogin/SSH and every non-navigation CSI sequence are
 * left byte-for-byte identical, and doorway mode is untouched.
 */
final class DoorHandlerRawInputTest extends TestCase
{
    private DoorHandler $handler;
    private ReflectionMethod $rawMethod;
    private ReflectionMethod $doorwayMethod;

    protected function setUp(): void
    {
        $ref = new ReflectionClass(DoorHandler::class);
        $this->handler = $ref->newInstanceWithoutConstructor();
        $this->rawMethod = $ref->getMethod('processRawTelnetInput');
        $this->rawMethod->setAccessible(true);
        $this->doorwayMethod = $ref->getMethod('processTelnetInput');
        $this->doorwayMethod->setAccessible(true);
    }

    /** @param array<string,mixed> $state */
    private function raw(string $data, string $backendType = 'native', array $state = []): string
    {
        return $this->rawMethod->invokeArgs($this->handler, [$data, &$state, $backendType]);
    }

    /** @param array<string,mixed> $state */
    private function rawState(string $data, array &$state, string $backendType = 'native'): string
    {
        return $this->rawMethod->invokeArgs($this->handler, [$data, &$state, $backendType]);
    }

    /** @param array<string,mixed> $state */
    private function doorway(string $data, array $state = []): string
    {
        return $this->doorwayMethod->invokeArgs($this->handler, [$data, &$state]);
    }

    // ---- 1-4: native cursor keys normal-mode CSI -> SS3 ----------------

    public function testNativeUpArrowIsNormalizedToSs3(): void
    {
        self::assertSame("\x1bOA", $this->raw("\x1b[A"));
    }

    public function testNativeDownArrowIsNormalizedToSs3(): void
    {
        self::assertSame("\x1bOB", $this->raw("\x1b[B"));
    }

    public function testNativeRightArrowIsNormalizedToSs3(): void
    {
        self::assertSame("\x1bOC", $this->raw("\x1b[C"));
    }

    public function testNativeLeftArrowIsNormalizedToSs3(): void
    {
        self::assertSame("\x1bOD", $this->raw("\x1b[D"));
    }

    public function testNativeCursorKeysInAStreamAreNormalizedInPlace(): void
    {
        // interleaved with ordinary input, all four directions
        self::assertSame(
            "l\x1bOAi\x1bOC\x1bOD\x1bOBx",
            $this->raw("l\x1b[Ai\x1b[C\x1b[D\x1b[Bx")
        );
    }

    // ---- 5-6: ordinary and control bytes untouched --------------------

    public function testOrdinarySingleByteInputIsUnchanged(): void
    {
        self::assertSame('i', $this->raw('i'));
        self::assertSame('hjkl', $this->raw('hjkl'));
    }

    public function testControlByteIsUnchanged(): void
    {
        self::assertSame("\x03", $this->raw("\x03"));       // Ctrl+C
        self::assertSame("\x1b", $this->raw("\x1b"));       // bare ESC
        self::assertSame("\x12\x18", $this->raw("\x12\x18")); // Ctrl+R, Ctrl+X
    }

    // ---- 7: unrelated CSI / ANSI data is preserved exactly -----------

    public function testUnrelatedCsiSequencesArePreserved(): void
    {
        // clear screen, modified (ctrl) arrow, xterm ~-form nav keys, SGR
        self::assertSame("\x1b[2J",    $this->raw("\x1b[2J"));
        self::assertSame("\x1b[1;5C",  $this->raw("\x1b[1;5C")); // Ctrl+Right, not a bare cursor key
        self::assertSame("\x1b[5~",    $this->raw("\x1b[5~"));   // PageUp (identical in both DECCKM modes)
        self::assertSame("\x1b[6~",    $this->raw("\x1b[6~"));   // PageDown
        self::assertSame("\x1b[H",     $this->raw("\x1b[H"));    // Home - not in scope, left as-is
        self::assertSame("\x1b[F",     $this->raw("\x1b[F"));    // End  - not in scope, left as-is
        self::assertSame("\x1bOP",     $this->raw("\x1bOP"));    // already-SS3 F1, unchanged
        self::assertSame("\x1b[0m",    $this->raw("\x1b[0m"));   // SGR reset
    }

    public function testTruncatedCursorPrefixAtEndOfReadIsPassedThrough(): void
    {
        // fragmentation is deliberately NOT buffered; ESC[ with no final byte
        // passes through byte-for-byte (same as before this change).
        self::assertSame("\x1b[", $this->raw("\x1b["));
        self::assertSame("\x1b",  $this->raw("\x1b"));
    }

    // ---- 8: IAC handling still correct -------------------------------

    public function testIacNegotiationIsStrippedAndEscapedIacIsLiteral(): void
    {
        // IAC WILL ECHO (255 251 1) is consumed; surrounding payload survives
        self::assertSame('ab', $this->raw("a\xff\xfb\x01b"));
        // IAC IAC -> literal 0xFF
        self::assertSame("\xff", $this->raw("\xff\xff"));
        // IAC DO/DONT/WONT consume their option byte
        self::assertSame('z', $this->raw("\xff\xfd\x03z"));
    }

    public function testNawsSubnegotiationUpdatesStateAndEmitsNoPayload(): void
    {
        // IAC SB NAWS(31) 0x00 0x50 (80) 0x00 0x18 (24) IAC SE
        $state = [];
        $out = $this->rawState("\xff\xfa\x1f\x00\x50\x00\x18\xff\xf0", $state);
        self::assertSame('', $out);
        self::assertSame(80, $state['cols']);
        self::assertSame(24, $state['rows']);
    }

    // ---- 9: CR / CRLF / CR-NUL framing preserved --------------------

    public function testEnterFramingCollapsesToBareCr(): void
    {
        self::assertSame("\r",       $this->raw("\r\n"));   // NVT CRLF
        self::assertSame("\r",       $this->raw("\r\x00")); // NVT CR NUL
        self::assertSame("\r",       $this->raw("\r"));     // bare CR
        self::assertSame("a\rb",     $this->raw("a\r\nb")); // CRLF mid-stream
    }

    // ---- 10: scope gate - RLogin / unknown backend NOT normalized ---

    public function testRloginBackendCursorKeysAreNotNormalized(): void
    {
        self::assertSame("\x1b[A", $this->raw("\x1b[A", 'rlogin'));
        self::assertSame("\x1b[C", $this->raw("\x1b[C", 'rlogin'));
    }

    public function testUnknownOrDosBackendCursorKeysAreNotNormalized(): void
    {
        self::assertSame("\x1b[A", $this->raw("\x1b[A", ''));
        self::assertSame("\x1b[A", $this->raw("\x1b[A", 'dos'));
    }

    // ---- 11: SSH transport gated out ------------------------------

    public function testSshNativeSessionsAreNotNormalized(): void
    {
        // SSH clients negotiate DECCKM themselves and send SS3 already.
        $state = ['isSsh' => true];
        self::assertSame("\x1b[A", $this->rawState("\x1b[A", $state, 'native'));
        $state2 = ['isSsh' => false];
        self::assertSame("\x1bOA", $this->rawState("\x1b[A", $state2, 'native'));
    }

    // ---- 12: doorway mode is completely unaffected ----------------

    public function testDoorwayModeStillConvertsCursorKeysToScanCodes(): void
    {
        // ESC[A/B/C/D -> Doorway protocol 0x00 + IBM scan code (unchanged behavior)
        self::assertSame("\x00\x48", $this->doorway("\x1b[A")); // Up
        self::assertSame("\x00\x50", $this->doorway("\x1b[B")); // Down
        self::assertSame("\x00\x4d", $this->doorway("\x1b[C")); // Right
        self::assertSame("\x00\x4b", $this->doorway("\x1b[D")); // Left
    }
}
