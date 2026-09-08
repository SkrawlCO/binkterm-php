<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/OutputSink.php';
require_once __DIR__ . '/../../telnet/src/BufferSink.php';
require_once __DIR__ . '/../../telnet/src/SocketSink.php';
require_once __DIR__ . '/../../telnet/src/GlyphPolicy.php';
require_once __DIR__ . '/../../telnet/src/TerminalCapabilities.php';
require_once __DIR__ . '/../../telnet/src/TerminalRenderContext.php';
require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalBoxRenderer.php';
require_once __DIR__ . '/../../telnet/src/BbsSession.php';

use BinktermPHP\AppearanceConfig;
use BinktermPHP\I18n\Translator;
use BinktermPHP\TelnetServer\BbsSession;
use BinktermPHP\TelnetServer\BufferSink;
use BinktermPHP\TelnetServer\TerminalBoxRenderer;
use BinktermPHP\TelnetServer\TerminalCapabilities;
use BinktermPHP\TelnetServer\TerminalRenderContext;
use BinktermPHP\TelnetServer\TelnetUtils;
use PHPUnit\Framework\TestCase;

/**
 * F1 Commit 2 — proves that routing BbsSession's render accessors through the
 * TerminalRenderContext is byte-for-byte equivalent to the historical inline
 * behaviour, across the {geometry} x {charset} x {colour} matrix.
 *
 * "Old path" = a BbsSession with a null render context (the pre-F1 inline
 * fallbacks). "New path" = the same session state pushed into a context.
 */
final class BbsSessionRenderSeamParityTest extends TestCase
{
    /** @return resource */
    private function mem()
    {
        return fopen('php://temp', 'r+');
    }

    private function newSession($conn): BbsSession
    {
        // isSsh=false → asciiTextMode defaults to true in the ctor.
        return new BbsSession($conn, 'http://127.0.0.1', false, false, false, false);
    }

    private function set(object $o, string $prop, $value): void
    {
        $r = new \ReflectionProperty($o, $prop);
        $r->setAccessible(true);
        $r->setValue($o, $value);
    }

    private function get(object $o, string $prop)
    {
        $r = new \ReflectionProperty($o, $prop);
        $r->setAccessible(true);
        return $r->getValue($o);
    }

    /**
     * Configure a session for the "old" (null context) path.
     */
    private function oldPathSession(string $charset, bool $color): BbsSession
    {
        $s = $this->newSession($this->mem());
        $this->set($s, 'renderContext', null);
        $this->set($s, 'terminalCharset', $charset);
        $this->set($s, 'ansiColorEnabled', $color);
        $this->set($s, 'asciiTextMode', $charset === 'ascii');
        TelnetUtils::setAnsiColorEnabled($color);
        return $s;
    }

    /**
     * Configure a session for the "new" (context) path with matching state.
     */
    private function newPathSession(string $charset, bool $color): array
    {
        $s = $this->newSession($this->mem());
        $sink = new BufferSink();
        $ctx = new TerminalRenderContext(
            $sink,
            TerminalCapabilities::unknown(),
            80,
            24,
            $charset,
            $color,
            $charset === 'ascii',
            [],
            'en',
            new Translator(),
            AppearanceConfig::getTermBorderStyle()
        );
        $this->set($s, 'renderContext', $ctx);
        $this->set($s, 'capabilities', TerminalCapabilities::unknown());
        $this->set($s, 'terminalCharset', $charset);
        $this->set($s, 'ansiColorEnabled', $color);
        $this->set($s, 'asciiTextMode', $charset === 'ascii');
        TelnetUtils::setAnsiColorEnabled($color);
        return [$s, $ctx, $sink];
    }

    /** @return array<int,array{string,bool}> */
    public static function charsetColorMatrix(): array
    {
        $out = [];
        foreach (['utf8', 'cp437', 'ascii'] as $cs) {
            foreach ([true, false] as $color) {
                $out["{$cs}/" . ($color ? 'color' : 'mono')] = [$cs, $color];
            }
        }
        return $out;
    }

    /** @dataProvider charsetColorMatrix */
    public function testAccessorParity(string $charset, bool $color): void
    {
        $old = $this->oldPathSession($charset, $color);
        [$new] = $this->newPathSession($charset, $color);

        self::assertSame($old->getTerminalCharset(), $new->getTerminalCharset(), 'charset');
        self::assertSame($old->getTerminalLineDrawingChars(), $new->getTerminalLineDrawingChars(), 'glyphs');
        self::assertSame(
            $old->colorizeForTerminal('LABEL', "\033[36m"),
            $new->colorizeForTerminal('LABEL', "\033[36m"),
            'colorizeForTerminal'
        );

        $sample = "menu \xe2\x94\x82 caf\xc3\xa9 \xe2\x86\x92 end";
        self::assertSame(
            $old->encodeForTerminal($sample),
            $new->encodeForTerminal($sample),
            'encodeForTerminal'
        );

        self::assertSame(
            $old->t('ui.terminalserver.__f1_missing__', "Caf\xc3\xa9 {n} \xe2\x86\x92", ['n' => 7]),
            $new->t('ui.terminalserver.__f1_missing__', "Caf\xc3\xa9 {n} \xe2\x86\x92", ['n' => 7]),
            't() fallback + transliteration'
        );

        // A real catalog key so translate() returns a hit, not the key.
        self::assertSame(
            $old->t('ui.terminalserver.server.goodbye', 'Goodbye!', [], 'en'),
            $new->t('ui.terminalserver.server.goodbye', 'Goodbye!', [], 'en'),
            't() catalog hit'
        );
    }

    /** @dataProvider geometryCharsetColorMatrix */
    public function testRenderBoxByteParity(int $cols, int $rows, string $charset, bool $color): void
    {
        $title = "Café → Status";
        $lines = [
            "Plain line one",
            "Unicode \xe2\x94\x9c\xe2\x94\x80 arrow \xe2\x86\x92 accent \xc3\xa9",
            str_repeat("wide content ", 20),
        ];

        // ---- old path: renderBox writes to the socket resource ----
        $oldConn = $this->mem();
        $old = $this->newSession($oldConn);
        $this->set($old, 'renderContext', null);
        $this->set($old, 'terminalCharset', $charset);
        $this->set($old, 'ansiColorEnabled', $color);
        $this->set($old, 'asciiTextMode', $charset === 'ascii');
        TelnetUtils::setAnsiColorEnabled($color);
        $oldState = ['cols' => $cols, 'rows' => $rows];
        (new TerminalBoxRenderer($old))->renderBox($oldConn, $oldState, $title, $lines);
        rewind($oldConn);
        $oldBytes = stream_get_contents($oldConn);
        fclose($oldConn);

        // ---- new path: renderBox routes through the context's BufferSink ----
        [$new, $ctx, $sink] = $this->newPathSession($charset, $color);
        $ctx->setGeometry($cols, $rows);
        $newState = ['cols' => $cols, 'rows' => $rows];
        (new TerminalBoxRenderer($new))->renderBox($this->mem(), $newState, $title, $lines);
        $newBytes = $sink->getBytes();

        self::assertSame(
            bin2hex($oldBytes),
            bin2hex($newBytes),
            "renderBox byte parity at {$cols}x{$rows} {$charset} " . ($color ? 'color' : 'mono')
        );
        self::assertNotSame('', $newBytes, 'sanity: something was rendered');
    }

    /** @return array<string,array{int,int,string,bool}> */
    public static function geometryCharsetColorMatrix(): array
    {
        $out = [];
        foreach ([[80, 24], [132, 51]] as [$c, $r]) {
            foreach (['utf8', 'cp437', 'ascii'] as $cs) {
                foreach ([true, false] as $color) {
                    $out["{$c}x{$r}/{$cs}/" . ($color ? 'color' : 'mono')] = [$c, $r, $cs, $color];
                }
            }
        }
        return $out;
    }

    protected function tearDown(): void
    {
        TelnetUtils::setAnsiColorEnabled(true); // restore process default
    }
}
