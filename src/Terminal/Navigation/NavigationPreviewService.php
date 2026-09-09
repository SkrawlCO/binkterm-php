<?php

namespace BinktermPHP\Terminal\Navigation;

use BinktermPHP\I18n\Translator;
use BinktermPHP\TelnetServer\BufferSink;
use BinktermPHP\TelnetServer\TerminalCapabilities;
use BinktermPHP\TelnetServer\TerminalRenderContext;

/**
 * Deterministic, off-session rendering of a declarative navigation definition.
 *
 * This is the hard part of a future sysop editor solved now: a preview needs no
 * live socket, no {@see \BinktermPHP\TelnetServer\BbsSession}, no auth, and no
 * database. It renders through the SAME {@see NavigationScreenRenderer} the live
 * session uses, into a {@see BufferSink}, at whatever geometry / charset /
 * colour / capability / locale the {@see NavigationPreviewProfile} asks for.
 */
final class NavigationPreviewService
{
    private ActionRegistry $registry;
    private Translator $translator;

    public function __construct(?ActionRegistry $registry = null, ?Translator $translator = null)
    {
        self::ensureRenderSeamLoaded();
        $this->registry   = $registry ?? TerminalActionCatalog::defaultRegistry();
        $this->translator = $translator ?? new Translator();
    }

    /**
     * Render one node of a definition through the canonical flowing renderer
     * and return the raw terminal bytes. This is the theme-independent content
     * preview; {@see renderReport()} applies the presentation theme.
     */
    public function render(
        NavigationDefinition $definition,
        NavigationPreviewProfile $profile,
        ?string $nodeId = null
    ): string {
        $sink   = new BufferSink();
        $ctx    = $this->context($sink, $profile);
        $screen = $this->screen($definition, $profile, $nodeId);

        (new NavigationScreenRenderer())->render($ctx, $screen);

        return $sink->getBytes();
    }

    /**
     * Render one node through the SAME renderer selection the live terminal
     * uses — a validated, enabled theme for a themed geometry, the flowing
     * renderer otherwise — and report which path ran.
     *
     * `lines` is a flattened 80x24-style grid (absolute positioning resolved,
     * SGR preserved) so a browser preview that only understands SGR can show
     * the themed layout faithfully; for the fallback path it is the flowing
     * output split into lines.
     *
     * @return array{
     *     bytes:string, mode:string, reason:?string,
     *     geometry:string, lines:array<int,string>,
     *     theme_status:string, theme_errors:array<int,string>
     * }
     */
    public function renderReport(
        NavigationDefinition $definition,
        NavigationPreviewProfile $profile,
        ?string $nodeId = null
    ): array {
        $sink   = new BufferSink();
        $ctx    = $this->context($sink, $profile);
        $screen = $this->screen($definition, $profile, $nodeId);
        $inner  = new NavigationScreenRenderer();

        $result = NavigationThemeConfig::load();
        $mode   = 'fallback';
        $reason = null;

        if ($result->isOk() && $result->theme() !== null && $result->theme()->isEnabled()) {
            $themed = new ThemedNavigationRenderer($inner, $result->theme());
            $themed->render($ctx, $screen);
            $report = $themed->lastReport();
            $mode   = $report['mode'];
            $reason = $report['reason'];
        } else {
            $inner->render($ctx, $screen);
            $reason = match (true) {
                $result->isAbsent()                               => 'no theme configured',
                $result->isInvalid()                              => 'theme config invalid: ' . $result->errorSummary(),
                $result->theme() !== null && !$result->theme()->isEnabled() => 'theme present but disabled',
                default                                           => 'flowing renderer',
            };
        }

        $bytes = $sink->getBytes();

        return [
            'bytes'        => $bytes,
            'mode'         => $mode,
            'reason'       => $reason,
            'geometry'     => $profile->cols . 'x' . $profile->rows,
            'lines'        => (new AnsiScreenBuffer($profile->cols, $profile->rows))->write($bytes)->toLines(),
            'theme_status' => $result->isOk() ? 'ok' : ($result->isInvalid() ? 'invalid' : 'absent'),
            'theme_errors' => array_map(static fn ($e) => (string) $e, $result->errors()),
        ];
    }

    /**
     * Build the resolved screen model without rendering (for structural
     * assertions / an editor's outline view).
     */
    public function screen(
        NavigationDefinition $definition,
        NavigationPreviewProfile $profile,
        ?string $nodeId = null
    ): NavigationScreenModel {
        $nodeId ??= $definition->rootId;
        $path = $this->pathTo($definition, $nodeId);

        return $this->builder()->build($definition, $this->accessContext($profile), $path, $profile->locale);
    }

    /**
     * The standard geometry x charset x colour preview matrix (R5I minimum set).
     *
     * @return array<string,NavigationPreviewProfile>
     */
    public static function standardProfiles(): array
    {
        $mk = static fn (string $geo, string $cs, bool $color) => NavigationPreviewProfile::geometry($geo)
            ->with(['charset' => $cs, 'color' => $color]);

        return [
            '80x24 utf8 color'   => $mk('80x24', 'utf8', true),
            '80x24 cp437 color'  => $mk('80x24', 'cp437', true),
            '80x24 ascii mono'   => $mk('80x24', 'ascii', false),
            '132x36 utf8 color'  => $mk('132x36', 'utf8', true),
            '132x51 utf8 color'  => $mk('132x51', 'utf8', true),
        ];
    }

    private function builder(): NavigationScreenBuilder
    {
        return new NavigationScreenBuilder(
            $this->registry,
            fn (?string $key, string $fallback, string $locale) => $this->translate($key, $fallback, $locale),
        );
    }

    private function translate(?string $key, string $fallback, string $locale): string
    {
        if ($key === null || $key === '') {
            return $fallback;
        }
        $result = $this->translator->translate($key, [], $locale !== '' ? $locale : null, ['terminalserver']);

        return $result === $key ? $fallback : $result;
    }

    private function accessContext(NavigationPreviewProfile $profile): AccessContext
    {
        $featureResolver = static fn (string $f) => !empty($profile->features[$f]);

        // `action:<id>` resolves against the action's own availability, which is
        // expressed purely in feature / auth / admin terms (never `action:`), so
        // a feature-only context is sufficient and cannot recurse.
        $baseCtx = new AccessContext(
            $profile->authenticated,
            $profile->admin,
            $profile->guest,
            $featureResolver,
            static fn () => false,
            $profile->capabilities,
        );

        return new AccessContext(
            $profile->authenticated,
            $profile->admin,
            $profile->guest,
            $featureResolver,
            fn (string $a) => $this->registry->isAvailable($a, $baseCtx),
            $profile->capabilities,
        );
    }

    private function context(BufferSink $sink, NavigationPreviewProfile $profile): TerminalRenderContext
    {
        $caps = TerminalCapabilities::unknown()
            ->withColorSupport($profile->color ? TerminalCapabilities::COLOR_ANSI : TerminalCapabilities::COLOR_NONE);
        if ($profile->charset === 'ascii') {
            $caps = $caps->withCharsetSupport(TerminalCapabilities::CHARSET_ASCII_ONLY);
        } elseif ($profile->charset === 'utf8') {
            $caps = $caps->withCharsetSupport(TerminalCapabilities::CHARSET_UTF8);
        }

        return new TerminalRenderContext(
            $sink,
            $caps,
            $profile->cols,
            $profile->rows,
            $profile->charset,
            $profile->color,
            $profile->charset === 'ascii',
            [],
            $profile->locale,
            $this->translator,
            $profile->borderStyle,
        );
    }

    private function pathTo(NavigationDefinition $definition, string $nodeId): NavigationPath
    {
        $rootLabel = $this->translate(
            $definition->root()->labelKey,
            $definition->root()->labelFallback,
            'en'
        );
        $path = NavigationPath::root($definition->rootId, $rootLabel);
        if ($nodeId === $definition->rootId) {
            return $path;
        }

        // Breadth-first search for the shortest submenu route to $nodeId.
        $queue = [[$definition->rootId, $path]];
        $seen  = [$definition->rootId => true];
        while ($queue !== []) {
            [$currentId, $currentPath] = array_shift($queue);
            foreach ($definition->node($currentId)->items as $item) {
                if (!$item->isSubmenu() || !$definition->hasNode($item->submenu) || isset($seen[$item->submenu])) {
                    continue;
                }
                $seen[$item->submenu] = true;
                $childLabel = $this->translate(
                    $definition->node($item->submenu)->labelKey,
                    $definition->node($item->submenu)->labelFallback,
                    'en'
                );
                $childPath = $currentPath->push($item->submenu, $childLabel);
                if ($item->submenu === $nodeId) {
                    return $childPath;
                }
                $queue[] = [$item->submenu, $childPath];
            }
        }

        // Not reachable via submenu links — show it as a standalone root.
        return NavigationPath::root($nodeId, $this->translate(
            $definition->node($nodeId)->labelKey,
            $definition->node($nodeId)->labelFallback,
            'en'
        ));
    }

    private static function ensureRenderSeamLoaded(): void
    {
        if (class_exists(TerminalRenderContext::class, false)) {
            return;
        }
        $seam = dirname(__DIR__, 3) . '/telnet/src';
        foreach (['OutputSink', 'BufferSink', 'SocketSink', 'GlyphPolicy', 'TerminalCapabilities', 'TerminalRenderContext'] as $file) {
            require_once "{$seam}/{$file}.php";
        }
    }
}
