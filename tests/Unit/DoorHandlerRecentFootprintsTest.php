<?php

declare(strict_types=1);

use BinktermPHP\TelnetServer\DoorHandler;
use BinktermPHP\TelnetServer\LineShell;
use BinktermPHP\TelnetServer\TelnetUtils;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalShellInterface.php';
require_once __DIR__ . '/../../telnet/src/LineShell.php';
require_once __DIR__ . '/../../telnet/src/DoorHandler.php';

/**
 * Telnet Crossroads "Recently in the Crossroads" — terminal-native footprints.
 *
 * The shared read model (ExperienceActivity::recentAcrossCatalog) already owns
 * authorization, distinct person + Experience collapsing, ordering and the
 * five-row cap; those are covered by ExperienceActivityTest. Here we cover the
 * terminal presentation contract only.
 */
final class DoorHandlerRecentFootprintsTest extends TestCase
{
    private static function translator(): callable
    {
        return static function (string $key, array $params = [], string $fallback = ''): string {
            $text = $fallback !== '' ? $fallback : $key;
            foreach ($params as $name => $value) {
                $text = str_replace('{' . $name . '}', (string)$value, $text);
            }
            return $text;
        };
    }

    /**
     * One row in the shape ExperienceActivity::recentAcrossCatalog() returns.
     *
     * @return array<string,mixed>
     */
    private static function footprint(
        string $username,
        string $experienceId,
        string $experienceName,
        string $type = 'play',
        int $secondsAgo = 600
    ): array {
        return [
            'id' => random_int(1, 1_000_000),
            'type' => $type,
            'user_id' => random_int(2, 9999),
            'username' => $username,
            'experience_id' => $experienceId,
            'experience_name' => $experienceName,
            'occurred_at' => gmdate('Y-m-d H:i:s', time() - $secondsAgo) . '+00',
        ];
    }

    // ---- composeRecentFootprints(): pure formatter --------------------------

    public function testComposesHeadingThenFootprintLinesThenBlankSeparator(): void
    {
        $block = DoorHandler::composeRecentFootprints([
            self::footprint('Bard', 'lord', 'Legend of the Red Dragon', 'play', 2820),
            self::footprint('Skrawl', 'lord', 'Legend of the Red Dragon', 'play', 2880),
        ], self::translator());

        self::assertSame(2, $block['count']);
        self::assertSame('Recently in the Crossroads', $block['lines'][0]);
        self::assertSame('Bard played Legend of the Red Dragon - 47m ago', $block['lines'][1]);
        self::assertSame('Skrawl played Legend of the Red Dragon - 48m ago', $block['lines'][2]);
        // Trailing blank separates the block from the "Experiences" cue.
        self::assertSame('', $block['lines'][3]);
    }

    public function testRendersCurrentCatalogExperienceNameAndUsername(): void
    {
        $block = DoorHandler::composeRecentFootprints([
            self::footprint('Skrawl', 'usurper', 'Usurper Reborn', 'play', 7200),
        ], self::translator());

        self::assertStringContainsString('Skrawl', $block['lines'][1]);
        self::assertStringContainsString('Usurper Reborn', $block['lines'][1]);
    }

    public function testFirstPlayRendersTruthfully(): void
    {
        $block = DoorHandler::composeRecentFootprints([
            self::footprint('Bard', 'lord', 'Legend of the Red Dragon', 'first_play', 60),
            self::footprint('Skrawl', 'lord', 'Legend of the Red Dragon', 'play', 120),
        ], self::translator());

        self::assertSame('Bard first played Legend of the Red Dragon - 1m ago', $block['lines'][1]);
        self::assertSame('Skrawl played Legend of the Red Dragon - 2m ago', $block['lines'][2]);
    }

    public function testCapsFootprintsAtFive(): void
    {
        $rows = [];
        foreach (range(1, 9) as $i) {
            $rows[] = self::footprint('User' . $i, 'lord', 'Legend of the Red Dragon', 'play', $i * 60);
        }

        $block = DoorHandler::composeRecentFootprints($rows, self::translator());

        self::assertSame(5, $block['count']);
        // 1 heading + 5 footprints + 1 blank.
        self::assertCount(7, $block['lines']);
    }

    public function testEmptyActivityYieldsNoBlock(): void
    {
        $block = DoorHandler::composeRecentFootprints([], self::translator());

        self::assertSame(['lines' => [], 'count' => 0], $block);
    }

    public function testDoesNotApplyASecondDedupeOnRowsFromTheSharedReadModel(): void
    {
        // recentAcrossCatalog() already collapses same-user/same-Experience;
        // if two such rows are ever handed here, Telnet must not silently drop
        // one — it renders exactly the footprints it is given.
        $block = DoorHandler::composeRecentFootprints([
            self::footprint('Bard', 'lord', 'Legend of the Red Dragon', 'play', 60),
            self::footprint('Bard', 'lord', 'Legend of the Red Dragon', 'play', 120),
        ], self::translator());

        self::assertSame(2, $block['count']);
    }

    public function testSameUserInDifferentExperiencesProducesMultipleFootprints(): void
    {
        $block = DoorHandler::composeRecentFootprints([
            self::footprint('Bard', 'lord', 'Legend of the Red Dragon', 'play', 60),
            self::footprint('Bard', 'usurper', 'Usurper Reborn', 'play', 120),
        ], self::translator());

        self::assertSame(2, $block['count']);
        self::assertStringContainsString('Legend of the Red Dragon', $block['lines'][1]);
        self::assertStringContainsString('Usurper Reborn', $block['lines'][2]);
    }

    public function testDifferentUsersInTheSameExperienceProduceMultipleFootprints(): void
    {
        $block = DoorHandler::composeRecentFootprints([
            self::footprint('Bard', 'lord', 'Legend of the Red Dragon', 'play', 60),
            self::footprint('Skrawl', 'lord', 'Legend of the Red Dragon', 'play', 120),
        ], self::translator());

        self::assertSame(2, $block['count']);
        self::assertStringContainsString('Bard', $block['lines'][1]);
        self::assertStringContainsString('Skrawl', $block['lines'][2]);
    }

    public function testSkipsRowsMissingUsernameOrExperienceName(): void
    {
        $block = DoorHandler::composeRecentFootprints([
            ['type' => 'play', 'username' => '', 'experience_name' => 'X', 'occurred_at' => '2026-08-30 12:00:00+00'],
            ['type' => 'play', 'username' => 'Bard', 'experience_name' => '', 'occurred_at' => '2026-08-30 12:00:00+00'],
            self::footprint('Skrawl', 'lord', 'Legend of the Red Dragon', 'play', 60),
        ], self::translator());

        self::assertSame(1, $block['count']);
        self::assertStringContainsString('Skrawl', $block['lines'][1]);
    }

    // ---- formatRecentFootprintTime(): compact terminal relative time -------

    public function testRelativeTimeIsCompactAndTerminalAppropriate(): void
    {
        $t = self::translator();

        self::assertSame('just now', DoorHandler::formatRecentFootprintTime(0, $t));
        self::assertSame('just now', DoorHandler::formatRecentFootprintTime(59, $t));
        self::assertSame('1m ago', DoorHandler::formatRecentFootprintTime(60, $t));
        self::assertSame('47m ago', DoorHandler::formatRecentFootprintTime(47 * 60, $t));
        self::assertSame('1h ago', DoorHandler::formatRecentFootprintTime(3600, $t));
        self::assertSame('5h ago', DoorHandler::formatRecentFootprintTime(5 * 3600 + 40, $t));
        self::assertSame('1d ago', DoorHandler::formatRecentFootprintTime(86400, $t));
        self::assertSame('12d ago', DoorHandler::formatRecentFootprintTime(12 * 86400 + 500, $t));
        // Negative (clock skew) clamps to zero.
        self::assertSame('just now', DoorHandler::formatRecentFootprintTime(-30, $t));
    }

    // ---- Rendering contract: non-selectable block before "Experiences" ----

    public function testTuiNormalizerPlacesFootprintLinesBeforeTheExperiencesCue(): void
    {
        $method = new ReflectionMethod(TelnetUtils::class, 'normalizeStructuredSelectableRow');
        $method->setAccessible(true);

        $block = $method->invoke(null, [
            'section_before_lines' => [
                'Recently in the Crossroads',
                'Bard played Legend of the Red Dragon - 47m ago',
                '',
            ],
            'section_before' => 'Experiences',
            'label' => 'Legend of the Red Dragon - Multiplayer',
            'detail' => '',
        ], 80);

        self::assertSame(
            ['Recently in the Crossroads', 'Bard played Legend of the Red Dragon - 47m ago', ''],
            $block['section_before_lines']
        );
        // The selectable label and the "Experiences" cue are untouched.
        self::assertSame('Experiences', $block['section_before']);
        self::assertSame(['Legend of the Red Dragon - Multiplayer'], $block['lines']);
    }

    public function testLineShellNormalizerCarriesFootprintLinesSeparatelyFromLabel(): void
    {
        $shell = (new ReflectionClass(LineShell::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(LineShell::class, 'normalizeListItem');
        $method->setAccessible(true);

        $row = $method->invoke($shell, [
            'section_before_lines' => ['Recently in the Crossroads', 'Bard played LORD - 47m ago'],
            'section_before' => 'Experiences',
            'label' => 'Legend of the Red Dragon - Multiplayer',
            'detail' => '',
        ]);

        self::assertSame(['Recently in the Crossroads', 'Bard played LORD - 47m ago'], $row['section_before_lines']);
        self::assertSame('Experiences', $row['section_before']);
        self::assertSame('Legend of the Red Dragon - Multiplayer', $row['label']);
    }

    public function testStructuredRendererEmitsFootprintLinesAboveTheSectionHeading(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../telnet/src/TelnetUtils.php');
        $fn = $this->between($source, 'private static function runSelectableStructuredList(', "\n    }\n");

        $footprintWrite = strpos($fn, "\$blocks[\$i]['section_before_lines'] ?? []");
        $headingWrite = strpos($fn, "\$sectionBefore = (string)(\$blocks[\$i]['section_before'] ?? '');");
        self::assertNotFalse($footprintWrite);
        self::assertNotFalse($headingWrite);
        // The footprint lines are written to the screen before the heading line.
        self::assertLessThan($headingWrite, $footprintWrite);
        // Height accounting includes the extra lines so they never clip the list.
        self::assertStringContainsString("+ count(\$blocks[\$i]['section_before_lines'] ?? [])", $fn);
    }

    public function testLineShellRendererEmitsFootprintLinesAboveTheSectionHeading(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../telnet/src/LineShell.php');
        $fn = $this->between($source, 'private function renderListPage(', "\n    }\n");

        $footprintWrite = strpos($fn, "foreach (\$itemData['section_before_lines'] as \$sectionLine)");
        $headingWrite = strpos($fn, "if (\$itemData['section_before'] !== '')");
        self::assertNotFalse($footprintWrite);
        self::assertNotFalse($headingWrite);
        self::assertLessThan($headingWrite, $footprintWrite);
    }

    private function between(string $haystack, string $start, string $end): string
    {
        $s = strpos($haystack, $start);
        self::assertNotFalse($s);
        $e = strpos($haystack, $end, $s);
        self::assertNotFalse($e);
        return substr($haystack, $s, $e - $s);
    }

    public function testStructuredRowWithoutFootprintLinesIsUnchanged(): void
    {
        $method = new ReflectionMethod(TelnetUtils::class, 'normalizeStructuredSelectableRow');
        $method->setAccessible(true);

        $block = $method->invoke(null, [
            'section_before' => 'Experiences',
            'label' => 'BBSLink - Gateway',
            'detail' => '',
        ], 80);

        self::assertSame('Experiences', $block['section_before']);
        self::assertSame([], $block['section_before_lines']);
        self::assertSame(['BBSLink - Gateway'], $block['lines']);
    }

    // ---- show(): wiring, one query, non-selectable, numbering intact -------

    public function testArrivalWiresOneSharedReadAndNeverConsumesAMenuNumber(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../telnet/src/DoorHandler.php');
        $showStart = strpos($source, 'public function show(');
        $showEnd = strpos($source, 'public static function composeLiveNow(', $showStart);
        self::assertNotFalse($showStart);
        self::assertNotFalse($showEnd);
        $show = substr($source, $showStart, $showEnd - $showStart);

        // The arrival composes from the single pre-chooser collection snapshot;
        // the recent-activity read joins that one boundary.
        $preChooser = substr(
            $show,
            (int)strpos($show, 'while (true) {'),
            (int)strpos($show, '$selected = $shell->chooseFromList(') - (int)strpos($show, 'while (true) {')
        );

        // Exactly one bounded recent-activity read, from the already-authorized
        // terminal catalog ($doorList) — no second GameCatalog discovery, no
        // per-Experience activity query, no extra collection-state read.
        self::assertSame(1, substr_count($show, 'recentAcrossCatalog('));
        self::assertStringContainsString("recentAcrossCatalog(\n                        array_column(\$doorList, 'data'),\n                        5\n                    )", $show);
        self::assertStringNotContainsString('getEnabledGames(', $show);
        self::assertSame(1, substr_count($preChooser, 'getExperienceStates('));

        // The footprints are attached as a non-selectable block on the first
        // Experience item — NOT pushed as their own $items entry — so the
        // Live Now (0) / Your Places (1) / Experience ($selected - 2) contract
        // is unchanged.
        self::assertStringContainsString("\$items[\$firstExperienceIndex]['section_before_lines'] =", $show);
        self::assertStringContainsString('$firstExperienceIndex = count($items);', $show);
        self::assertStringContainsString("if (\$recentFootprints['count'] > 0)", $show);
        self::assertStringContainsString('isset($items[$firstExperienceIndex])', $show);
        self::assertStringContainsString('$entry = $doorList[$selected - 2]', $show);
        self::assertStringContainsString('if ($selected === 0)', $show);
        self::assertStringContainsString('if ($selected === 1)', $show);

        // composeRecentFootprints is invoked after the Experiences items are
        // built, so the block always renders after Live Now / Your Places.
        self::assertGreaterThan(
            strpos($show, 'buildYourPlacesArrivalItem'),
            strpos($show, 'composeRecentFootprints(')
        );
        self::assertGreaterThan(
            strpos($show, 'self::buildExperienceListItem('),
            strpos($show, 'composeRecentFootprints(')
        );
    }
}
