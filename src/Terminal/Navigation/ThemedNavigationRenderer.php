<?php

namespace BinktermPHP\Terminal\Navigation;

use BinktermPHP\TelnetServer\TerminalRenderContext;

/**
 * Wraps the canonical {@see NavigationScreenRenderer} with an absolute-
 * positioned ANSI presentation for one exact geometry.
 *
 * Responsibilities are strictly presentation:
 *   - load + sanitise the trusted template art for the current geometry
 *   - ask the canonical renderer to compose the schema's semantic blocks
 *   - paint the template, then place each block inside its validated rectangle
 *   - never interpret an action, a hotkey, an ACS predicate, or navigation
 *     structure — those all belong to the definition and the runtime
 *
 * Fallback is a first-class path, decided on every render (the geometry can
 * change mid-session): whenever the theme does not apply — size not themed,
 * charset/colour unsuitable, template missing or unsafe — it delegates to the
 * inner renderer unchanged. {@see lastReport()} records which path ran and why,
 * for the F6 preview and the live log.
 */
final class ThemedNavigationRenderer implements NavigationRenderer
{
    private const MODE_THEMED   = 'themed';
    private const MODE_FALLBACK = 'fallback';

    /** @var array{token:string,charset:string,art:string}|null */
    private ?array $templateCache = null;

    /** @var array{mode:string,reason:?string,geometry:?string} */
    private array $lastReport = ['mode' => self::MODE_FALLBACK, 'reason' => 'not rendered yet', 'geometry' => null];

    /**
     * @param callable(string):void|null $logger optional; called once with a
     *        human-readable reason the first time a render falls back
     */
    public function __construct(
        private readonly NavigationScreenRenderer $inner,
        private readonly NavigationTheme $theme,
        private $logger = null,
    ) {
    }

    public function render(TerminalRenderContext $ctx, NavigationScreenModel $screen, array $opts = []): void
    {
        $geoKey = $ctx->cols() . 'x' . $ctx->rows();
        $plan   = $this->theme->rootOnly && !$screen->path->isRoot()
            ? $this->cannot('theme applies only to the root screen') : $this->plan($ctx);

        if (!$plan['ok']) {
            $this->recordFallback($plan['reason'], $geoKey);
            $this->inner->render($ctx, $screen, $opts);

            return;
        }

        try {
            $this->paint($ctx, $screen, $opts, $plan['geometry'], $plan['art']);
            $this->lastReport = ['mode' => self::MODE_THEMED, 'reason' => null, 'geometry' => $geoKey];
        } catch (\Throwable $e) {
            $this->recordFallback('themed render failed: ' . $e->getMessage(), $geoKey);
            // Reset a possibly half-painted screen, then fall back cleanly.
            $ctx->write("\033[0m\033[2J\033[H");
            $this->inner->render($ctx, $screen, $opts);
        }
    }

    /** @return array{mode:string,reason:?string,geometry:?string} */
    public function lastReport(): array
    {
        return $this->lastReport;
    }

    /**
     * @return array{ok:bool,reason:?string,geometry:?NavigationThemeGeometry,art:?string}
     */
    private function plan(TerminalRenderContext $ctx): array
    {
        $charset = $ctx->effectiveCharset();
        if ($charset !== 'utf8' && $charset !== 'cp437') {
            return $this->cannot("terminal charset '{$charset}' cannot render ANSI art");
        }
        if (!$ctx->isColorEnabled()) {
            return $this->cannot('terminal has ANSI colour disabled');
        }

        $geo = $this->theme->forGeometry($ctx->cols(), $ctx->rows());
        if ($geo === null) {
            return $this->cannot(sprintf(
                '%dx%d is not a themed geometry (themed: %s)',
                $ctx->cols(),
                $ctx->rows(),
                implode(', ', $this->theme->geometryKeys())
            ));
        }

        $art = $this->template($geo->templateToken, $charset);
        if ($art === null) {
            return $this->cannot("template asset '{$geo->templateToken}.ans' is missing");
        }
        if (trim($art) === '') {
            return $this->cannot("template '{$geo->templateToken}' has no safe content in charset '{$charset}'");
        }

        if ($this->theme->schema === NavigationTheme::COMPOSITION_SCHEMA) {
            $plain = preg_replace('/\033\[[0-9;:]*m/', '', $art) ?? '';
            if ($charset === 'cp437') {
                $plain = iconv('CP437', 'UTF-8', $plain) ?: '';
            }
            $lines = explode("\n", rtrim($plain, "\n"));
            if (trim($plain) === '' || str_contains($plain, "\t")) {
                return $this->cannot('composition template has no printable content or contains tabs');
            }
            if (count($lines) > $geo->rows) {
                return $this->cannot('composition template exceeds geometry height');
            }
            foreach ($lines as $line) {
                if (mb_strlen($line, 'UTF-8') > $geo->cols) {
                    return $this->cannot('composition template exceeds geometry width');
                }
            }
        }

        return ['ok' => true, 'reason' => null, 'geometry' => $geo, 'art' => $art];
    }

    /**
     * @return array{ok:false,reason:string,geometry:null,art:null}
     */
    private function cannot(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'geometry' => null, 'art' => null];
    }

    private function template(string $token, string $charset): ?string
    {
        if ($this->templateCache !== null
            && $this->templateCache['token'] === $token
            && $this->templateCache['charset'] === $charset) {
            return $this->templateCache['art'];
        }

        $path = NavigationThemeConfig::templatePath($token);
        if ($path === null) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $art = TemplateArtSanitizer::sanitize($raw, $charset);
        $this->templateCache = ['token' => $token, 'charset' => $charset, 'art' => $art];

        return $art;
    }

    /**
     * @param array<string,mixed> $opts
     */
    private function paint(
        TerminalRenderContext $ctx,
        NavigationScreenModel $screen,
        array $opts,
        NavigationThemeGeometry $geo,
        string $art
    ): void {
        $menu   = $geo->menu();
        $footer = $geo->footer();

        $semantic = $this->theme->schema === NavigationTheme::COMPOSITION_SCHEMA;
        if ($semantic) {
            $blocks = $this->inner->composeSemanticRegions($ctx, $screen, $geo, $opts);
        } else {
            $blocks = $this->inner->composeRegions(
                $ctx,
                $screen,
                $menu->width,
                $menu->height,
                $footer->width,
                $footer->height,
                [
                    'show_hotkeys' => $opts['show_hotkeys'] ?? true,
                    'cursor'       => $opts['cursor'] ?? null,
                ]
            );
        }

        $artLines = explode("\n", $art);

        $ctx->beginFrame();
        try {
            $ctx->write("\033[0m\033[2J\033[H");

            // Template art — positioned line by line so it never scrolls or
            // wraps past the geometry.
            for ($i = 0; $i < $geo->rows; $i++) {
                if (!isset($artLines[$i])) {
                    break;
                }
                // Schema 2 has already validated the complete encoded row;
                // preserve CP437 bytes rather than passing them to UTF-8 clipping.
                $line = $semantic ? $artLines[$i] : $this->clipTemplateLine($artLines[$i], $geo->cols);
                $ctx->write("\033[" . ($i + 1) . ';1H' . $line);
            }

            if ($semantic) {
                foreach ($blocks as $name => $lines) {
                    $this->placeBlock($ctx, $lines, $geo->region($name));
                }
            } else {
                $this->placeBlock($ctx, $blocks['menu'], $menu);
                $this->placeBlock($ctx, $blocks['footer'], $footer);
            }

            // Park the cursor out of the way; the lightbar carries the selection.
            $ctx->write("\033[0m\033[" . $geo->rows . ';1H');
        } finally {
            $ctx->endFrame();
        }
    }

    /**
     * @param array<int,string> $lines already fitted to the region by composeRegions()
     */
    private function placeBlock(TerminalRenderContext $ctx, array $lines, NavigationThemeRegion $region): void
    {
        foreach ($lines as $i => $line) {
            $row = $region->row + $i;
            if ($row > $region->bottomRow()) {
                break;
            }
            $ctx->write("\033[{$row};{$region->col}H" . $line);
        }
    }

    /**
     * Clip a template line to the geometry width without splitting an SGR
     * sequence or a multibyte glyph. SGR is the only escape the sanitiser
     * leaves in, so this only has to step over `ESC [ ... m`.
     */
    private function clipTemplateLine(string $line, int $width): string
    {
        $tokens  = preg_split('/(\033\[[0-9;:]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out     = '';
        $visible = 0;
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if ($token[0] === "\033") {
                $out .= $token;
                continue;
            }
            foreach (mb_str_split($token, 1, 'UTF-8') as $ch) {
                if ($visible >= $width) {
                    return $out . "\033[0m";
                }
                $out .= $ch;
                $visible++;
            }
        }

        return $out;
    }

    private function recordFallback(string $reason, string $geoKey): void
    {
        $firstTime = $this->lastReport['mode'] !== self::MODE_FALLBACK
            || $this->lastReport['reason'] !== $reason;

        $this->lastReport = ['mode' => self::MODE_FALLBACK, 'reason' => $reason, 'geometry' => $geoKey];

        if ($firstTime && is_callable($this->logger)) {
            ($this->logger)(sprintf(
                'Themed navigation not applied for %s (using flowing renderer): %s',
                $geoKey,
                $reason
            ));
        }
    }
}
