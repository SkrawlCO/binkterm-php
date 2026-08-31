<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use BinktermPHP\DashboardCardRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Registration and gating of dashboard cards, with focus on the Crossroads
 * pulse card added as an optional, conditionally-available main-zone card.
 */
final class DashboardCardRegistryTest extends TestCase
{
    private const NON_ADMIN = ['is_admin' => false];

    public function testCrossroadsCardIsRegisteredAsOptionalMainZone(): void
    {
        $card = DashboardCardRegistry::getAllCards()['crossroads'] ?? null;

        self::assertIsArray($card);
        self::assertSame('main', $card['default_zone']);
        self::assertFalse($card['required']);
        self::assertSame('ui.dashboard.card.crossroads', $card['label_key']);
        self::assertSame('crossroads_available', $card['conditional']);
    }

    public function testCrossroadsCardIsHiddenWhenNotAvailable(): void
    {
        $available = DashboardCardRegistry::getAvailableCards(self::NON_ADMIN, [
            'crossroads_available' => false,
        ]);

        self::assertArrayNotHasKey('crossroads', $available);
    }

    public function testCrossroadsCardAppearsWhenAvailable(): void
    {
        $available = DashboardCardRegistry::getAvailableCards(self::NON_ADMIN, [
            'crossroads_available' => true,
        ]);

        self::assertArrayHasKey('crossroads', $available);
    }

    public function testCrossroadsCardOmittedWhenConditionAbsentEntirely(): void
    {
        // Absent condition key is treated as falsey by the registry.
        $available = DashboardCardRegistry::getAvailableCards(self::NON_ADMIN, []);

        self::assertArrayNotHasKey('crossroads', $available);
    }

    public function testCrossroadsSitsAfterSystemNewsInDefaultMainLayout(): void
    {
        $available = DashboardCardRegistry::getAvailableCards(self::NON_ADMIN, [
            'crossroads_available' => true,
        ]);
        $layout = DashboardCardRegistry::getDefaultLayout($available);

        $main = $layout['main'];
        self::assertContains('crossroads', $main);
        self::assertGreaterThan(
            array_search('system_news', $main, true),
            array_search('crossroads', $main, true),
            'crossroads should follow system_news in the main column'
        );
    }

    public function testHiddenCrossroadsCardStaysHiddenAfterMerge(): void
    {
        $available = DashboardCardRegistry::getAvailableCards(self::NON_ADMIN, [
            'crossroads_available' => true,
        ]);

        $merged = DashboardCardRegistry::mergeLayout(
            ['main' => ['unread', 'system_news'], 'sidebar' => [], 'hidden' => ['crossroads']],
            $available
        );

        self::assertContains('crossroads', $merged['hidden']);
        self::assertNotContains('crossroads', $merged['main']);
    }

    /**
     * Every card declaring a `conditional` must have a matching flag produced by
     * resolveConditions(); otherwise the save endpoint and the render route
     * disagree about which cards are available and a user's ordering choice for
     * that card is dropped on save.
     */
    public function testResolveConditionsCoversEveryConditionalCard(): void
    {
        $conditionKeys = array_keys(DashboardCardRegistry::resolveConditions());

        foreach (DashboardCardRegistry::getAllCards() as $id => $card) {
            if (empty($card['conditional'])) {
                continue;
            }
            self::assertContains(
                $card['conditional'],
                $conditionKeys,
                "resolveConditions() is missing '{$card['conditional']}' for the '{$id}' card"
            );
        }
    }

    /**
     * The reported bug: a conditional card the layout-save validator does not
     * know about is stripped from the submitted layout, so its user-chosen
     * position is lost. This is the mechanism, not a desired behaviour.
     */
    public function testValidateLayoutStripsCardMissingFromAvailableSet(): void
    {
        $withoutCrossroads = DashboardCardRegistry::getAvailableCards(self::NON_ADMIN, [
            'crossroads_available' => false,
        ]);

        $saved = DashboardCardRegistry::validateLayout(
            ['main' => ['crossroads', 'unread', 'system_news'], 'sidebar' => [], 'hidden' => []],
            $withoutCrossroads
        );

        self::assertNotContains('crossroads', $saved['main']);
    }

    /**
     * Regression: with the available set resolved consistently, a user who
     * reorders Crossroads to the top keeps it there across a save + reload.
     */
    public function testReorderedCrossroadsSurvivesSaveThenReload(): void
    {
        $available = DashboardCardRegistry::getAvailableCards(self::NON_ADMIN, [
            'crossroads_available' => true,
        ]);

        // User drags Crossroads to the very top of the main column and saves.
        $submitted = ['main' => ['crossroads', 'unread', 'system_news'], 'sidebar' => [], 'hidden' => []];
        $saved = DashboardCardRegistry::validateLayout($submitted, $available);
        self::assertSame(0, array_search('crossroads', $saved['main'], true));

        // Next page load re-merges the saved layout against the available set.
        $reloaded = DashboardCardRegistry::mergeLayout($saved, $available);
        self::assertSame(
            0,
            array_search('crossroads', $reloaded['main'], true),
            'Crossroads should stay at the top it was dragged to'
        );
        self::assertNotContains('crossroads', $reloaded['sidebar']);
        self::assertNotContains('crossroads', $reloaded['hidden']);
    }

    public function testReorderedThenHiddenCrossroadsKeepsBothChoicesAcrossReload(): void
    {
        $available = DashboardCardRegistry::getAvailableCards(self::NON_ADMIN, [
            'crossroads_available' => true,
        ]);

        $submitted = [
            'main' => ['unread', 'crossroads', 'system_news'],
            'sidebar' => [],
            'hidden' => ['crossroads'],
        ];
        $saved = DashboardCardRegistry::validateLayout($submitted, $available);
        $reloaded = DashboardCardRegistry::mergeLayout($saved, $available);

        self::assertSame(1, array_search('crossroads', $reloaded['main'], true));
        self::assertContains('crossroads', $reloaded['hidden']);
    }

    /**
     * An existing user whose saved layout predates the Crossroads card gets it
     * inserted at its registry default position on the first load, and later
     * reordering is then honoured (covered above) — i.e. the default insertion
     * does not fight a user's explicit choice.
     */
    public function testLegacyLayoutWithoutCrossroadsGetsItAtDefaultPositionOnce(): void
    {
        $available = DashboardCardRegistry::getAvailableCards(self::NON_ADMIN, [
            'crossroads_available' => true,
        ]);

        $legacy = ['main' => ['unread', 'system_news', 'shoutbox'], 'sidebar' => [], 'hidden' => []];
        $merged = DashboardCardRegistry::mergeLayout($legacy, $available);

        self::assertSame(
            array_search('system_news', $merged['main'], true) + 1,
            array_search('crossroads', $merged['main'], true),
            'first-time insertion lands right after system_news'
        );
    }
}
