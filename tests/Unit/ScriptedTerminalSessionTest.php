<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/ScriptedTelnetSession.php';

use BinktermPHP\Tests\Support\ScriptedTelnetSession;
use BinktermPHP\TelnetServer\TerminalCapabilities;
use PHPUnit\Framework\TestCase;

/**
 * F4 — scripted terminal session regression harness.
 *
 * End-to-end scenarios that feed a full protocol conversation (opening
 * negotiation, IAC responses, TTYPE, NAWS, CHARSET, keystrokes, arrow escapes,
 * a mid-session resize, malformed bytes, EOF) into the real
 * {@see BinktermPHP\TelnetServer\BbsSession} engine and assert on the resolved
 * capabilities, geometry, effective charset / colour, normalised input tokens,
 * negotiation bytes, and clean degradation.
 *
 * In-memory paired sockets only — no external BBS, no SyncTerm, no network, no
 * timing-fragile sleeps, and the real Telnet parser (never a copy).
 */
final class ScriptedTerminalSessionTest extends TestCase
{
    private ScriptedTelnetSession $s;

    protected function tearDown(): void
    {
        $this->s->close();
        unset($_ENV['TELNET_KEEPALIVE_SECONDS']);
    }

    public function testFullSyncTermConversationResolvesCapabilitiesAndGeometry(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();

        // Client answers the opening burst.
        $this->s->sendWill(ScriptedTelnetSession::OPT_TTYPE)
                ->sendWill(ScriptedTelnetSession::OPT_NAWS)
                ->sendWill(ScriptedTelnetSession::OPT_SUPPRESS_GA)
                ->sendDo(ScriptedTelnetSession::OPT_ECHO)
                ->pump();

        // TTYPE cycling: SYNCTERM then a generic fallback then a repeat.
        $this->s->sendTerminalTypeIs('SYNCTERM')->pump();
        $this->s->sendTerminalTypeIs('ANSI')->pump();
        $this->s->sendTerminalTypeIs('ANSI')->pump();

        // Window size.
        $this->s->sendNaws(132, 50)->pump();

        // CHARSET handshake.
        $this->s->sendDo(ScriptedTelnetSession::OPT_CHARSET)->pump();
        $this->s->serverOutput();
        $this->s->sendCharsetAccepted('UTF-8')->pump();

        $caps = $this->s->capabilities();
        self::assertTrue($caps->isSyncTerm());
        self::assertSame(TerminalCapabilities::COLOR_ANSI, $caps->colorSupport);
        self::assertSame(TerminalCapabilities::CHARSET_UTF8, $caps->charsetSupport);
        self::assertSame([132, 50], $this->s->geometry());
    }

    public function testDumbClientConversationDegradesToNoColour(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->sendWill(ScriptedTelnetSession::OPT_TTYPE)->pump();
        $this->s->serverOutput();
        $this->s->sendTerminalTypeIs('DUMB')->pump();
        $this->s->sendTerminalTypeIs('DUMB')->pump();

        self::assertSame(TerminalCapabilities::COLOR_NONE, $this->s->capabilities()->colorSupport);
    }

    public function testKeystrokesAndArrowEscapesNormaliseToTokens(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->serverOutput();

        // Interleave a NAWS update between keystrokes: the resize must not eat a key.
        $this->s->send('h')
                ->send('i')
                ->sendNaws(100, 30)
                ->send("\033[A")   // up arrow
                ->send("\033[B")   // down arrow
                ->send("\r");      // enter
        $tokens = [];
        for ($i = 0; $i < 20; $i++) {
            [$tok, $timedOut, $disc] = $this->s->readKey(20);
            if ($disc) {
                break;
            }
            if ($timedOut) {
                break;
            }
            if ($tok !== '' && $tok !== null) {
                $tokens[] = $tok;
            }
        }

        self::assertSame(['CHAR:h', 'CHAR:i', 'UP', 'DOWN', 'ENTER'], $tokens);
        self::assertSame([100, 30], $this->s->geometry(), 'the interleaved resize was applied');
    }

    public function testStandaloneEscByteNormalisesToTheEscToken(): void
    {
        // Live: SyncTerm's Esc key sends a lone 0x1B with no continuation.
        // readRawChar()'s ~50ms disambiguation window elapses, and the timeout
        // reader must surface it as 'ESC' (it used to return an empty token).
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->serverOutput();

        $this->s->send("\x1b"); // standalone ESC, nothing follows
        [$tok, $timedOut, $disc] = $this->s->readKey(200);

        self::assertFalse($disc);
        self::assertFalse($timedOut);
        self::assertSame('ESC', $tok);
    }

    public function testEscDoesNotStealARealArrowSequence(): void
    {
        // ESC that IS the prefix of an arrow (arrives as one write) stays an
        // arrow — not ESC + garbage — and a following standalone ESC is still ESC.
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->serverOutput();

        $this->s->send("\x1b[C")   // right arrow
                ->send("\x1b[D")   // left arrow
                ->send("\x1b");    // then a genuine standalone ESC

        $tokens = [];
        for ($i = 0; $i < 8; $i++) {
            [$tok, $to, $dc] = $this->s->readKey(200);
            if ($dc || $to) {
                break;
            }
            if ($tok !== '' && $tok !== null) {
                $tokens[] = $tok;
            }
        }

        self::assertSame(['RIGHT', 'LEFT', 'ESC'], $tokens);
    }

    public function testEscImmediatelyFollowedByAPrintableIsHeldForTheNextRead(): void
    {
        // A byte within the disambiguation window after ESC is pushed back (it
        // could be Alt+<key>), so ESC is still delivered as ESC and the byte is
        // read on the next keystroke — not lost, not merged.
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->serverOutput();

        $this->s->send("\x1bz");   // ESC then 'z' in one write

        [$first] = $this->s->readKey(200);
        self::assertSame('ESC', $first);

        // 'z' is now in pushback; the next real key on the wire flushes it first.
        $this->s->send('y');
        $rest = [];
        for ($i = 0; $i < 6; $i++) {
            [$tok, $to, $dc] = $this->s->readKey(200);
            if ($dc || $to) {
                break;
            }
            if ($tok !== '' && $tok !== null) {
                $rest[] = $tok;
            }
        }
        self::assertSame(['CHAR:z', 'CHAR:y'], $rest);
    }

    public function testResizeMidSessionUpdatesGeometryForTheNextRender(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        self::assertSame([80, 24], $this->s->geometry());

        $this->s->sendNaws(132, 60)->pump();
        self::assertSame([132, 60], $this->s->geometry());
        self::assertSame(60, $this->s->renderContext()->selectorRows(), 'non-SyncTERM: full height');

        // Shrink back.
        $this->s->sendNaws(80, 25)->pump();
        self::assertSame([80, 25], $this->s->geometry());
    }

    public function testSyncTermSelectorRowReservationSurvivesResize(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->sendWill(ScriptedTelnetSession::OPT_TTYPE)->pump();
        $this->s->serverOutput();
        $this->s->sendTerminalTypeIs('SYNCTERM')->pump();

        $this->s->sendNaws(80, 25)->pump();
        self::assertSame(24, $this->s->renderContext()->selectorRows(), 'SyncTERM keeps one row reserved');

        $this->s->sendNaws(132, 51)->pump();
        self::assertSame(50, $this->s->renderContext()->selectorRows());
    }

    public function testMalformedBytesDoNotDesyncSubsequentInput(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->serverOutput();

        $this->s->send(chr(255) . chr(255))              // IAC IAC -> one literal 0xFF
                ->send('ok')
                ->send(chr(255) . chr(253) . chr(40))    // IAC DO <unsupported> -> refused, no token
                ->send('!');
        $tokens = $this->s->pump();

        self::assertSame([chr(255), 'o', 'k', '!'], $tokens, 'input stream stays aligned through malformed/negotiation bytes');
        self::assertStringContainsString(
            chr(255) . chr(252) . chr(40),
            $this->s->serverOutput(),
            'the unsolicited DO for an unsupported option mid-stream was refused with WONT'
        );
    }

    public function testClientDisconnectIsReportedCleanly(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->serverOutput();

        $this->s->disconnect();
        [$tok, $timedOut, $shouldDisconnect] = $this->s->readKey(20);

        self::assertNull($tok);
        self::assertTrue($shouldDisconnect, 'EOF surfaces as a disconnect, not a hang');
    }

    public function testSshConversationSkipsTelnetNegotiationButStillResolvesGeometry(): void
    {
        $this->s = new ScriptedTelnetSession(true);
        // No negotiate() for SSH. Geometry is seeded by the harness; a render
        // context still exists and tracks it.
        self::assertSame([80, 24], $this->s->geometry());
        self::assertSame('none', $this->stringOr($this->s->serverOutput(), 'none'));
    }

    public function testNonBlockingReadReturnsPromptlyWhenNothingIsPending(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->serverOutput();

        $start = microtime(true);
        [$tok, $timedOut] = $this->s->readKey(30);
        $elapsedMs = (microtime(true) - $start) * 1000;

        self::assertTrue($timedOut);
        self::assertLessThan(500, $elapsedMs, 'a quiet read is bounded by its timeout, not blocking');
    }

    private function stringOr(string $value, string $fallback): string
    {
        return $value === '' ? $fallback : $value;
    }
}
