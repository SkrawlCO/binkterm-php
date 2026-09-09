<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Support;

require_once __DIR__ . '/../../../telnet/src/OutputSink.php';
require_once __DIR__ . '/../../../telnet/src/BufferSink.php';
require_once __DIR__ . '/../../../telnet/src/SocketSink.php';
require_once __DIR__ . '/../../../telnet/src/GlyphPolicy.php';
require_once __DIR__ . '/../../../telnet/src/TerminalCapabilities.php';
require_once __DIR__ . '/../../../telnet/src/TerminalRenderContext.php';
require_once __DIR__ . '/../../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../../telnet/src/TerminalBoxRenderer.php';
require_once __DIR__ . '/../../../telnet/src/BbsSession.php';

use BinktermPHP\I18n\Translator;
use BinktermPHP\TelnetServer\BbsSession;
use BinktermPHP\TelnetServer\SocketSink;
use BinktermPHP\TelnetServer\TerminalCapabilities;
use BinktermPHP\TelnetServer\TerminalRenderContext;

/**
 * ScriptedTelnetSession — F4 scripted terminal session regression harness.
 *
 * Drives the *real* {@see BbsSession} Telnet engine (negotiation, IAC handling,
 * the raw-character reader, the timeout-bounded key reader) over an in-memory
 * {@see stream_socket_pair()} — no network, no external BBS, no SyncTerm, no
 * timing-fragile sleeps, and no re-implementation of the protocol parser.
 *
 * One end of the pair is handed to `BbsSession`; the other is "the client" that
 * the test script writes negotiation / keystroke bytes into and reads the
 * server's responses out of. The harness never spins its own event loop: it
 * pumps the engine one call at a time and every read is bounded.
 *
 * Typical use:
 *
 *   $s = (new ScriptedTelnetSession())->negotiate();
 *   $s->sendWill(BbsSession::optTtype())->pump();
 *   $s->sendTerminalTypeIs('XTERM-256COLOR')->pump();
 *   self::assertSame('XTERM-256COLOR', $s->capabilities()->clientType);
 */
final class ScriptedTelnetSession
{
    // Telnet wire constants (kept local so the harness reads as a client, and so
    // a rename inside BbsSession is caught by the byte-exchange assertions).
    public const IAC  = 255;
    public const DONT = 254;
    public const DO   = 253;
    public const WONT = 252;
    public const WILL = 251;
    public const SB   = 250;
    public const SE   = 240;
    public const NOP  = 241;

    public const OPT_ECHO        = 1;
    public const OPT_SUPPRESS_GA = 3;
    public const OPT_TTYPE       = 24;
    public const OPT_NAWS        = 31;
    public const OPT_CHARSET     = 42;
    public const OPT_BINARY      = 0;

    public const CHARSET_REQUEST  = 1;
    public const CHARSET_ACCEPTED  = 2;
    public const CHARSET_REJECTED = 3;

    /** @var resource */
    private $server;
    /** @var resource */
    private $client;
    private BbsSession $session;
    private array $state;

    public function __construct(bool $ssh = false, ?int $keepaliveSeconds = 0)
    {
        // Deterministic keepalive: off by default so byte-exchange assertions are
        // not polluted; a keepalive test passes an explicit interval.
        if ($keepaliveSeconds === null) {
            unset($_ENV['TELNET_KEEPALIVE_SECONDS']);
        } else {
            $_ENV['TELNET_KEEPALIVE_SECONDS'] = (string) $keepaliveSeconds;
        }

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false) {
            throw new \RuntimeException('stream_socket_pair() unavailable');
        }
        [$this->server, $this->client] = $pair;
        stream_set_blocking($this->server, false);
        stream_set_blocking($this->client, false);

        $this->session = new BbsSession($this->server, 'http://127.0.0.1', false, false, false, $ssh);
        $this->state = [
            'telnet_mode'             => null,
            'input_echo'              => true,
            'cols'                    => 80,
            'rows'                    => 24,
            'terminal_type'           => '',
            'terminal_info_logged'    => false,
            'last_activity'           => time(),
            'idle_warned'             => false,
            'idle_warning_timeout'    => 300,
            'idle_disconnect_timeout' => 420,
            'pushback'                => '',
            'locale'                  => 'en',
            'isTls'                   => false,
            'isSsh'                   => $ssh,
        ];

        // Build the render context exactly as BbsSession::run() does so the
        // render seam is live for geometry / capability assertions.
        $caps = TerminalCapabilities::unknown();
        $ctx  = new TerminalRenderContext(
            new SocketSink($this->server),
            $caps,
            80,
            24,
            'ascii',
            true,
            !$ssh,
            [],
            'en',
            new Translator()
        );
        $this->inject('capabilities', $caps);
        $this->inject('renderContext', $ctx);
    }

    // ===== BbsSession private-symbol accessors (harness-only) =====

    public static function optTtype(): int   { return self::OPT_TTYPE; }
    public static function optNaws(): int    { return self::OPT_NAWS; }
    public static function optCharset(): int { return self::OPT_CHARSET; }

    private function inject(string $prop, mixed $value): void
    {
        $r = new \ReflectionProperty($this->session, $prop);
        $r->setAccessible(true);
        $r->setValue($this->session, $value);
    }

    private function invokePrivate(string $method, array $args): mixed
    {
        $r = new \ReflectionMethod($this->session, $method);
        $r->setAccessible(true);

        return $r->invokeArgs($this->session, $args);
    }

    // ===== script actions =====

    /** Run the server's opening Telnet negotiation burst. */
    public function negotiate(): self
    {
        $this->invokePrivate('negotiateTelnet', [$this->server]);

        return $this;
    }

    /** Write raw bytes onto the wire as if the client sent them. */
    public function send(string $bytes): self
    {
        $written = @fwrite($this->client, $bytes);
        if ($written === false || $written !== strlen($bytes)) {
            throw new \RuntimeException('short client write to socket pair');
        }

        return $this;
    }

    public function sendCommand(int $cmd, int $opt): self
    {
        return $this->send(chr(self::IAC) . chr($cmd) . chr($opt));
    }

    public function sendWill(int $opt): self { return $this->sendCommand(self::WILL, $opt); }
    public function sendWont(int $opt): self { return $this->sendCommand(self::WONT, $opt); }
    public function sendDo(int $opt): self   { return $this->sendCommand(self::DO, $opt); }
    public function sendDont(int $opt): self { return $this->sendCommand(self::DONT, $opt); }

    public function sendSb(int $opt, string $data): self
    {
        // No IAC-doubling of $data here: the callers below never embed 0xFF.
        return $this->send(chr(self::IAC) . chr(self::SB) . chr($opt) . $data . chr(self::IAC) . chr(self::SE));
    }

    /** RFC 1091 "TERMINAL-TYPE IS <name>". */
    public function sendTerminalTypeIs(string $name): self
    {
        return $this->sendSb(self::OPT_TTYPE, chr(0) . $name);
    }

    /** RFC 1073 window size. */
    public function sendNaws(int $cols, int $rows): self
    {
        $data = chr(($cols >> 8) & 0xFF) . chr($cols & 0xFF)
              . chr(($rows >> 8) & 0xFF) . chr($rows & 0xFF);

        return $this->sendSb(self::OPT_NAWS, $data);
    }

    /** RFC 2066 "CHARSET ACCEPTED <name>". */
    public function sendCharsetAccepted(string $name): self
    {
        return $this->sendSb(self::OPT_CHARSET, chr(self::CHARSET_ACCEPTED) . $name);
    }

    /** RFC 2066 "CHARSET REJECTED". */
    public function sendCharsetRejected(): self
    {
        return $this->sendSb(self::OPT_CHARSET, chr(self::CHARSET_REJECTED));
    }

    /** RFC 2066 "CHARSET REQUEST <sep><names>" from a (misbehaving) client. */
    public function sendCharsetRequest(string $sep, string $names): self
    {
        return $this->sendSb(self::OPT_CHARSET, chr(self::CHARSET_REQUEST) . $sep . $names);
    }

    public function sendText(string $bytes): self
    {
        return $this->send($bytes);
    }

    /** Simulate the client vanishing (EOF). */
    public function disconnect(): self
    {
        @fclose($this->client);

        return $this;
    }

    // ===== engine pumping =====

    /**
     * Feed everything currently on the wire through the real raw-character
     * reader until it drains. Returns every non-empty token produced (a `null`
     * entry means the reader signalled disconnect).
     *
     * @return array<int,string|null>
     */
    public function pump(int $maxIterations = 100): array
    {
        $tokens = [];
        for ($i = 0; $i < $maxIterations; $i++) {
            if (!$this->serverHasData()) {
                break;
            }
            $c = $this->session->readRawChar($this->server, $this->state);
            if ($c === null) {
                $tokens[] = null;
                break;
            }
            if ($c === "\x00") {
                continue;
            }
            $tokens[] = $c;
        }

        return $tokens;
    }

    /**
     * Call the real timeout-bounded key reader once. Returns
     * [string|null $token, bool $timedOut, bool $shouldDisconnect].
     */
    public function readKey(int $timeoutMs = 40): array
    {
        return $this->session->readKeyWithTimeout($this->server, $this->state, $timeoutMs);
    }

    private function serverHasData(): bool
    {
        if (($this->state['pushback'] ?? '') !== '') {
            return true;
        }
        if (!is_resource($this->server)) {
            return false;
        }
        $r = [$this->server];
        $w = $e = null;

        return @stream_select($r, $w, $e, 0, 0) > 0;
    }

    // ===== observation =====

    /** Drain and return everything the server has written to the client. */
    public function serverOutput(): string
    {
        $out = '';
        while (is_resource($this->client)) {
            $chunk = @fread($this->client, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $out .= $chunk;
        }

        return $out;
    }

    public function state(): array { return $this->state; }

    public function session(): BbsSession { return $this->session; }

    public function renderContext(): TerminalRenderContext
    {
        return $this->session->getRenderContext();
    }

    public function capabilities(): TerminalCapabilities
    {
        return $this->renderContext()->capabilities();
    }

    public function geometry(): array
    {
        $ctx = $this->renderContext();

        return [$ctx->cols(), $ctx->rows()];
    }

    public function close(): void
    {
        if (is_resource($this->server)) { @fclose($this->server); }
        if (is_resource($this->client)) { @fclose($this->client); }
    }

    // ===== byte-exchange helpers for assertions =====

    /** Human-readable decode of a Telnet byte stream, for assertion messages. */
    public static function describe(string $bytes): string
    {
        $names = [
            240 => 'SE', 250 => 'SB', 251 => 'WILL', 252 => 'WONT',
            253 => 'DO', 254 => 'DONT', 255 => 'IAC', 241 => 'NOP',
        ];
        $opts = [
            0 => 'BINARY', 1 => 'ECHO', 3 => 'SGA', 24 => 'TTYPE',
            31 => 'NAWS', 42 => 'CHARSET',
        ];
        $out = [];
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($bytes[$i]);
            if ($b === self::IAC && $i + 1 < $len) {
                $cmd = ord($bytes[$i + 1]);
                $label = 'IAC ' . ($names[$cmd] ?? $cmd);
                if (in_array($cmd, [251, 252, 253, 254], true) && $i + 2 < $len) {
                    $o = ord($bytes[$i + 2]);
                    $label .= ' ' . ($opts[$o] ?? $o);
                    $i += 2;
                } elseif ($cmd === self::SB && $i + 2 < $len) {
                    $o = ord($bytes[$i + 2]);
                    $label .= ' ' . ($opts[$o] ?? $o);
                    $i += 2;
                } else {
                    $i += 1;
                }
                $out[] = $label;
                continue;
            }
            $out[] = ($b >= 32 && $b < 127) ? "'" . $bytes[$i] . "'" : sprintf('0x%02X', $b);
        }

        return implode(' ', $out);
    }
}
