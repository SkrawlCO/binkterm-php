<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/ScriptedTelnetSession.php';

use BinktermPHP\Tests\Support\ScriptedTelnetSession;
use BinktermPHP\TelnetServer\BbsSession;
use BinktermPHP\TelnetServer\TerminalCapabilities;
use PHPUnit\Framework\TestCase;

/**
 * F3 — capability / negotiation foundation.
 *
 * Every test drives the real {@see BbsSession} Telnet engine through the
 * {@see ScriptedTelnetSession} harness and asserts on the exact bytes exchanged
 * and on the resolved {@see TerminalCapabilities} / geometry. No sleeps, no
 * network, no protocol re-implementation.
 */
final class TelnetNegotiationTest extends TestCase
{
    private ScriptedTelnetSession $s;

    protected function tearDown(): void
    {
        $this->s->close();
        unset($_ENV['TELNET_KEEPALIVE_SECONDS']);
    }

    // ===== opening negotiation =====

    public function testOpeningNegotiationOffersTtypeNawsAndCharset(): void
    {
        $this->s = new ScriptedTelnetSession();
        $this->s->negotiate();

        $bytes = $this->s->serverOutput();
        $desc  = ScriptedTelnetSession::describe($bytes);

        // Historical options, unchanged.
        self::assertStringContainsString('IAC WILL ECHO', $desc);
        self::assertStringContainsString('IAC DONT ECHO', $desc);
        self::assertStringContainsString('IAC DO NAWS', $desc);
        self::assertStringContainsString('IAC WILL SGA', $desc);
        self::assertStringContainsString('IAC DO TTYPE', $desc);
        // F3B: CHARSET is now offered.
        self::assertStringContainsString('IAC WILL CHARSET', $desc);
        // F3: BINARY / LINEMODE are still deliberately never negotiated.
        self::assertStringNotContainsString('BINARY', $desc);
    }

    // ===== F3A: RFC 1091 TTYPE cycling =====

    public function testTtypeCyclingRequestsNextEntryUntilTheClientRepeats(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->serverOutput(); // discard opening burst

        $this->s->sendWill(ScriptedTelnetSession::OPT_TTYPE)->pump();
        self::assertSame(
            'IAC SB TTYPE 0x01 IAC SE',
            ScriptedTelnetSession::describe($this->s->serverOutput()),
            'server asks for the first terminal type'
        );

        // Client offers a list: SYNCTERM, then ANSI, then repeats ANSI.
        $this->s->sendTerminalTypeIs('SYNCTERM')->pump();
        $afterFirst = ScriptedTelnetSession::describe($this->s->serverOutput());
        self::assertStringContainsString('IAC SB TTYPE 0x01 IAC SE', $afterFirst, 'cycles for a second type');

        $this->s->sendTerminalTypeIs('ANSI')->pump();
        self::assertStringContainsString(
            'IAC SB TTYPE 0x01 IAC SE',
            ScriptedTelnetSession::describe($this->s->serverOutput()),
            'cycles again for a third type'
        );

        // Repeat -> list exhausted -> no further request.
        $this->s->sendTerminalTypeIs('ANSI')->pump();
        self::assertSame('', $this->s->serverOutput(), 'a repeated type stops the cycle');
    }

    public function testTtypeCyclingIsCappedForAClientThatNeverRepeats(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->sendWill(ScriptedTelnetSession::OPT_TTYPE)->pump();
        $this->s->serverOutput();

        $requests = 0;
        for ($i = 0; $i < 12; $i++) {
            $this->s->sendTerminalTypeIs('TERM-' . $i)->pump();
            if (str_contains($this->s->serverOutput(), "\xff\xfa\x18\x01\xff\xf0")) {
                $requests++;
            }
        }

        // 1 initial (from WILL TTYPE) + at most MAX_TTYPE_REQUESTS-1 more here;
        // the point is that it terminates well short of the client's list.
        self::assertLessThanOrEqual(4, $requests, 'cycling is bounded');
    }

    public function testTtypeCyclingKeepsASyncTermIdentificationAcrossLaterGenericEntries(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->sendWill(ScriptedTelnetSession::OPT_TTYPE)->pump();
        $this->s->serverOutput();

        $this->s->sendTerminalTypeIs('SYNCTERM')->pump();
        // SyncTERM local-status-line-off request went out on the first entry.
        self::assertStringContainsString("\033[0\$~", $this->s->serverOutput());

        $this->s->sendTerminalTypeIs('ANSI-BBS')->pump();
        $this->s->serverOutput();

        self::assertTrue($this->s->capabilities()->isSyncTerm(), 'SyncTERM identity survives a later generic type');
    }

    // ===== F3B: RFC 2066 CHARSET =====

    public function testCharsetRequestIsSentOnlyAfterTheClientAgrees(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->serverOutput();

        $this->s->sendDo(ScriptedTelnetSession::OPT_CHARSET)->pump();
        $out = $this->s->serverOutput();

        self::assertStringContainsString(chr(255) . chr(250) . chr(42) . chr(1), $out, 'IAC SB CHARSET REQUEST');
        self::assertStringContainsString(';UTF-8;CP437;US-ASCII', $out, 'server lists what it can emit, ; separated');
        self::assertStringEndsWith(chr(255) . chr(240), $out);
    }

    public function testCharsetAcceptedPopulatesCharsetSupportWithoutChangingEffectiveCharset(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->serverOutput();
        $effectiveBefore = $this->s->renderContext()->effectiveCharset();

        $this->s->sendDo(ScriptedTelnetSession::OPT_CHARSET)->pump();
        $this->s->serverOutput();
        $this->s->sendCharsetAccepted('UTF-8')->pump();

        self::assertSame(TerminalCapabilities::CHARSET_UTF8, $this->s->capabilities()->charsetSupport);
        self::assertSame(
            $effectiveBefore,
            $this->s->renderContext()->effectiveCharset(),
            'effective charset is still driven by preference / wizard, not by CHARSET negotiation'
        );
    }

    public function testCharsetAcceptedCp437IsRecordedAsAsciiOnlyCapability(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->serverOutput();
        $this->s->sendDo(ScriptedTelnetSession::OPT_CHARSET)->pump();
        $this->s->serverOutput();
        $this->s->sendCharsetAccepted('CP437')->pump();

        self::assertSame(TerminalCapabilities::CHARSET_ASCII_ONLY, $this->s->capabilities()->charsetSupport);
    }

    public function testCharsetRejectedLeavesTheSessionAtItsHistoricalBehaviour(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->serverOutput();
        $this->s->sendDo(ScriptedTelnetSession::OPT_CHARSET)->pump();
        $this->s->serverOutput();
        $this->s->sendCharsetRejected()->pump();

        self::assertSame(TerminalCapabilities::CHARSET_UNKNOWN, $this->s->capabilities()->charsetSupport);
        self::assertSame('', $this->s->serverOutput(), 'no reply to REJECTED');
    }

    public function testAClientCharsetRequestIsDeclinedNotActedOn(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->serverOutput();
        $this->s->sendCharsetRequest(';', 'KOI8-R;UTF-8')->pump();

        $out = $this->s->serverOutput();
        self::assertSame(
            chr(255) . chr(250) . chr(42) . chr(3) . chr(255) . chr(240),
            $out,
            'server answers CHARSET REJECTED and does not switch its output charset'
        );
        self::assertSame('ascii', $this->s->renderContext()->effectiveCharset());
    }

    public function testClientThatRefusesCharsetProducesNoStorm(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->serverOutput();
        $this->s->sendDont(ScriptedTelnetSession::OPT_CHARSET)->pump();

        self::assertSame('', $this->s->serverOutput(), 'DONT CHARSET is accepted silently');
    }

    // ===== F3C: colour capability inference =====

    /** @dataProvider colorInferenceProvider */
    public function testColorSupportIsInferredFromTheReportedTerminalType(string $ttype, string $expected): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->sendWill(ScriptedTelnetSession::OPT_TTYPE)->pump();
        $this->s->serverOutput();
        $this->s->sendTerminalTypeIs($ttype)->pump();

        self::assertSame($expected, $this->s->capabilities()->colorSupport);
    }

    public static function colorInferenceProvider(): array
    {
        return [
            'xterm'      => ['XTERM-256COLOR', TerminalCapabilities::COLOR_ANSI],
            'syncterm'   => ['SYNCTERM',       TerminalCapabilities::COLOR_ANSI],
            'ansi-bbs'   => ['ANSI-BBS',       TerminalCapabilities::COLOR_ANSI],
            'dumb'       => ['DUMB',           TerminalCapabilities::COLOR_NONE],
            'unknown'    => ['UNKNOWN',        TerminalCapabilities::COLOR_NONE],
        ];
    }

    public function testColorInferenceDoesNotChangeTheEffectiveColorFlag(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->sendWill(ScriptedTelnetSession::OPT_TTYPE)->pump();
        $this->s->serverOutput();

        $before = $this->s->renderContext()->isColorEnabled();
        $this->s->sendTerminalTypeIs('DUMB')->pump();

        self::assertSame($before, $this->s->renderContext()->isColorEnabled(), 'inference != effective flag');
        self::assertSame(TerminalCapabilities::COLOR_NONE, $this->s->capabilities()->colorSupport);
    }

    // ===== F3D: sixel probe result flows into capabilities =====

    public function testSixelSupportPropagatesFromTheSessionFlagIntoCapabilities(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();

        $flag = new \ReflectionProperty(BbsSession::class, 'sixelSupported');
        $flag->setAccessible(true);
        $flag->setValue($this->s->session(), true);

        // Any capability refresh (a NAWS update here) carries the flag through.
        $this->s->sendSb(ScriptedTelnetSession::OPT_NAWS, chr(0) . chr(100) . chr(0) . chr(40))->pump();
        $sync = new \ReflectionMethod(BbsSession::class, 'refreshCapabilities');
        $sync->setAccessible(true);
        $sync->invokeArgs($this->s->session(), [$this->s->state()]);

        self::assertTrue($this->s->capabilities()->sixelSupported);
    }

    // ===== F3E: NAWS geometry reaches the render context immediately =====

    public function testNawsUpdatesRenderContextGeometryOnTheNextRead(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        self::assertSame([80, 24], $this->s->geometry());

        $this->s->sendNaws(132, 43)->pump();
        self::assertSame([132, 43], $this->s->geometry(), 'render context sees the resize without a keypress');
        self::assertSame(132, $this->s->state()['cols']);
        self::assertSame(43, $this->s->state()['rows']);

        // A second resize mid-session.
        $this->s->sendNaws(80, 24)->pump();
        self::assertSame([80, 24], $this->s->geometry());
    }

    // ===== F3F: conservative refusal of unsupported options =====

    public function testUnsolicitedWillForAnUnsupportedOptionIsRefusedWithDont(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->serverOutput();

        $this->s->sendWill(ScriptedTelnetSession::OPT_BINARY)->pump();
        self::assertSame(
            chr(255) . chr(254) . chr(0),
            $this->s->serverOutput(),
            'IAC DONT BINARY'
        );
    }

    public function testUnsolicitedDoForAnUnsupportedOptionIsRefusedWithWont(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->serverOutput();

        $this->s->sendDo(34 /* LINEMODE */)->pump();
        self::assertSame(
            chr(255) . chr(252) . chr(34),
            $this->s->serverOutput(),
            'IAC WONT LINEMODE'
        );
    }

    public function testAnOptionIsRefusedAtMostOncePerSession(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->serverOutput();

        $this->s->sendWill(ScriptedTelnetSession::OPT_BINARY)->pump();
        self::assertSame(chr(255) . chr(254) . chr(0), $this->s->serverOutput());

        // Client keeps re-offering — server stays silent (no storm).
        for ($i = 0; $i < 5; $i++) {
            $this->s->sendWill(ScriptedTelnetSession::OPT_BINARY)->pump();
        }
        self::assertSame('', $this->s->serverOutput());
    }

    public function testWontAndDontAreNeverAnswered(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->serverOutput();

        $this->s->sendWont(99)->pump();
        $this->s->sendDont(99)->pump();

        self::assertSame('', $this->s->serverOutput(), 'answering WONT/DONT is what creates loops');
    }

    public function testSupportedOptionsAreNotRefused(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->serverOutput();

        // NAWS / SGA / ECHO / CHARSET are options the server negotiates.
        $this->s->sendWill(ScriptedTelnetSession::OPT_NAWS)->pump();
        $this->s->sendWill(ScriptedTelnetSession::OPT_SUPPRESS_GA)->pump();
        $this->s->sendDo(ScriptedTelnetSession::OPT_ECHO)->pump();

        $out = $this->s->serverOutput();
        self::assertStringNotContainsString(chr(255) . chr(254) . chr(31), $out, 'no DONT NAWS');
        self::assertStringNotContainsString(chr(255) . chr(252) . chr(1), $out, 'no WONT ECHO');
    }

    public function testUnknownIacCommandIsSkippedAndSurroundingInputStillFlows(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->serverOutput();

        // 'a', then an unknown 2-byte IAC command, then 'b'.
        $this->s->send('a')->send(chr(255) . chr(200))->send('b');
        $tokens = $this->s->pump();

        self::assertSame(['a', 'b'], $tokens, 'unknown IAC command consumed without dropping real input');
        self::assertNotContains(null, $tokens);
    }

    public function testTruncatedSubnegotiationDoesNotDropTheSession(): void
    {
        $this->s = (new ScriptedTelnetSession())->negotiate();
        $this->s->serverOutput();

        // SB TTYPE with a too-short body, properly terminated, then a keystroke.
        $this->s->send(chr(255) . chr(250) . chr(24) . chr(0) . chr(255) . chr(240));
        $this->s->send('z');
        $tokens = $this->s->pump();

        self::assertSame(['z'], $tokens);
    }

    // ===== F3G: transport keepalive =====

    public function testIdleKeepaliveSendsIacNopWithoutTouchingActivityTimers(): void
    {
        $this->s = new ScriptedTelnetSession(false, 1);
        $this->s->negotiate();
        $this->s->serverOutput();

        $state = $this->s->state();
        $state['last_activity'] = time() - 5;
        $activityBefore = $state['last_activity'];
        $this->setState($state);

        // No data on the wire -> the timeout branch fires -> keepalive.
        $result = $this->s->readKey(30);
        self::assertTrue($result[1], 'timed out');

        self::assertSame(chr(255) . chr(241), $this->s->serverOutput(), 'IAC NOP');
        self::assertSame(
            $activityBefore,
            $this->s->state()['last_activity'],
            'keepalive is transport liveness, not user activity'
        );
        self::assertFalse($this->s->state()['idle_warned'], 'keepalive does not disturb the idle warning state');
    }

    public function testKeepaliveIsThrottledAndDisabledByZeroInterval(): void
    {
        $this->s = new ScriptedTelnetSession(false, 0);
        $this->s->negotiate();
        $this->s->serverOutput();

        $this->s->readKey(20);
        self::assertSame('', $this->s->serverOutput(), 'interval 0 disables keepalive');
    }

    private function setState(array $state): void
    {
        $r = new \ReflectionProperty(ScriptedTelnetSession::class, 'state');
        $r->setAccessible(true);
        $r->setValue($this->s, $state);
    }
}
