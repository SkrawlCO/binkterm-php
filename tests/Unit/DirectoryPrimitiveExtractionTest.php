<?php

declare(strict_types=1);

use BinktermPHP\Terminal\Navigation\DefaultNavigationDefinition;
use BinktermPHP\Terminal\Navigation\NavigationPreviewService;
use BinktermPHP\Terminal\Navigation\NavigationScreenItem;
use BinktermPHP\Terminal\Navigation\NavigationScreenModel;
use BinktermPHP\Terminal\Navigation\NavigationPath;
use BinktermPHP\Terminal\Navigation\NavigationScreenRenderer;
use BinktermPHP\Terminal\Navigation\PresentationHints;
use BinktermPHP\Terminal\Navigation\TerminalActionCatalog;
use BinktermPHP\Terminal\Presentation\TextBlock;
use BinktermPHP\Tests\Support\TerminalRenderHarness;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/TerminalRenderHarness.php';

/**
 * Terminal Experience Unification, Part 1 — proves that pulling the pure
 * text-geometry helpers out of {@see NavigationScreenRenderer} into
 * {@see TextBlock} (shared with {@see \BinktermPHP\Terminal\Presentation\DirectoryView})
 * did not move the HUMAN ACCEPTED front door by a single byte.
 */
final class DirectoryPrimitiveExtractionTest extends TestCase
{
    // ===== the extracted helpers, pinned to their original semantics =====

    public function testPadRightFillsToWidthAndNeverTruncates(): void
    {
        self::assertSame('ab   ', TextBlock::padRight('ab', 5));
        self::assertSame('abcde', TextBlock::padRight('abcde', 5), 'exact fit is unchanged');
        self::assertSame('abcdef', TextBlock::padRight('abcdef', 5), 'over-width is never clipped');
        self::assertSame('', TextBlock::padRight('', 0));
        self::assertSame('é    ', TextBlock::padRight('é', 5), 'multibyte counts as one cell');
    }

    public function testEllipsizeReplacesTheTrailingCellWithAHorizontalEllipsis(): void
    {
        self::assertSame('abc', TextBlock::ellipsize('abc', 3), 'exact fit is unchanged');
        self::assertSame('abc', TextBlock::ellipsize('abc', 9), 'short string is unchanged');
        self::assertSame("ab\u{2026}", TextBlock::ellipsize('abcdef', 3));
        self::assertSame("a\u{2026}", TextBlock::ellipsize('abcdef', 2));
        self::assertSame('', TextBlock::ellipsize('abcdef', 0), 'zero width yields nothing');
        self::assertSame("\u{2026}", TextBlock::ellipsize('abcdef', 1));
    }

    // ===== the accepted front door renders identically =====

    /**
     * The default definition at every accepted geometry: deterministic, within
     * bounds, and still carrying its grouped section headings. If the extraction
     * had changed a width or a truncation, the byte-exact matrix in
     * NavigationRenderTest would already have caught it — this is the belt to
     * that suspenders, focused on the geometries humans accepted.
     */
    public function testAcceptedFrontDoorGeometriesRenderDeterministicallyAndStructurally(): void
    {
        $def     = DefaultNavigationDefinition::build();
        $service = new NavigationPreviewService();
        $catalog = TerminalActionCatalog::defaultRegistry();

        foreach (NavigationPreviewService::standardProfiles() as $name => $profile) {
            $profile = $profile->withAllFeaturesEnabled($catalog);
            $bytes   = $service->render($def, $profile);

            self::assertNotSame('', $bytes, "{$name}: renders something");
            self::assertSame(
                bin2hex($bytes),
                bin2hex($service->render($def, $profile)),
                "{$name}: deterministic"
            );

            $plain = preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $bytes) ?? $bytes;
            foreach (preg_split('/\r\n|\n/', $plain) ?: [] as $line) {
                self::assertLessThanOrEqual(
                    $profile->cols,
                    mb_strlen(rtrim($line, "\r"), 'UTF-8'),
                    "{$name}: '{$line}' within {$profile->cols} cols"
                );
            }
        }
    }

    public function testClipVisibleStillWiredIntoTheThemedDirectoryRow(): void
    {
        // A description far wider than any column forces the ellipsis path that
        // NavigationScreenRenderer::clipVisible() (now TextBlock::ellipsize())
        // owns; a one-word label exercises the pad path.
        $items = [
            new NavigationScreenItem(
                'x',
                'Go',
                str_repeat('verylongword ', 40),
                'x',
                'normal',
                true,
                null,
                NavigationScreenItem::KIND_ACTION,
                null,
                null,
            ),
        ];
        $screen = new NavigationScreenModel(
            'main',
            'Crossroads',
            'Where the networks meet.',
            $items,
            NavigationPath::root('main', 'Crossroads'),
            false,
            false,
            PresentationHints::none(),
        );

        $ctx = TerminalRenderHarness::at(80, 24)->charset('utf8')->context();
        $blocks = (new NavigationScreenRenderer())->composeRegions($ctx, $screen, 72, 12, 72, 2, ['cursor' => null]);

        $menu = implode("\n", $blocks['menu']);
        self::assertStringContainsString("\u{2026}", $menu, 'the over-wide description is ellipsised');
    }
}
