<?php

declare(strict_types=1);

namespace BinktermPHP\Messaging;

use BinktermPHP\Terminal\Presentation\TextBlock;

/**
 * Terminal-specific "Since Your Last Call" presentation reducer — the Telnet
 * sibling of {@see SylcPulse}, not a replacement for it. Both consume the
 * same {@see ActivityPlan}/{@see ActivityService} semantics; only the
 * presentation shape differs, because an 80x24 terminal sidebar has a
 * fraction of a Web dashboard card's row/width budget.
 *
 * Pure reducer, no queries, no authorization of its own — mirrors
 * {@see SylcPulse}'s and {@see \BinktermPHP\Crossroads\DashboardPulse}'s
 * discipline exactly. `$plan` comes from {@see ActivityService::plan()};
 * `$netmailRows`/`$replyRows` come from {@see SylcHydrator}, the same
 * presentation-only hydrator the Web card uses.
 */
final class TelnetSylcPresenter
{
    public const MAX_PERSONAL_ROWS = 2;
    public const MAX_AMBIENT_AREAS = 3;

    /**
     * The compact, always-visible sidebar line. Returns null when there is
     * nothing to show at all — a first-ever tracked visit (no previous visit
     * to summarize) or a quiet return (nothing personal or ambient) — so the
     * caller (the sidebar widget builder) simply omits the row rather than
     * rendering an empty/placeholder line. This is a deliberate divergence
     * from the Web card, which does acknowledge a quiet return; the terminal
     * sidebar is a scarce, actively-prioritized resource where an
     * always-present "nothing happened" row has a real, recurring space cost
     * (see /root/L33TEST_Messaging_Telnet_SYLC_Design_2026-09-14.md §10).
     *
     * @param callable(string,string,array):string $t translate(key, fallback, params)
     */
    public static function sidebarLine(ActivityPlan $plan, callable $t): ?string
    {
        if (!$plan->hasPreviousVisit) {
            return null;
        }

        $personalCount = self::personalCount($plan);
        $areaCount = count($plan->ambient['areas'] ?? []);

        if ($personalCount === 0 && $areaCount === 0) {
            return null;
        }

        $parts = [];
        if ($personalCount > 0) {
            $parts[] = $t(
                'ui.terminalserver.sylc.sidebar_personal',
                '{count} personal',
                ['count' => $personalCount]
            );
        }
        if ($areaCount > 0) {
            $parts[] = $t(
                'ui.terminalserver.sylc.sidebar_areas',
                '{count} area(s)',
                ['count' => $areaCount]
            );
        }

        $label = $t('ui.terminalserver.sylc.sidebar_label', 'Since Last Call', []);

        return $label . ': ' . implode(', ', $parts);
    }

    /**
     * The optional detail view's content. Never called for a first-ever
     * tracked visit (the caller — {@see \BinktermPHP\TelnetServer\SylcHandler}
     * — omits the menu entry entirely in that case, per Decision: no fake
     * previous-call history). Personal rows are listed before ambient areas
     * in the returned structure, and the panel builder must preserve that
     * order — personal relevance outranks ambient volume.
     *
     * @param array{id:int,from_name:string,subject:?string,date_received:string}[] $netmailRows
     * @param array{id:int,from_name:string,subject:?string,date_received:string}[] $replyRows
     * @param callable(string,string,array):string $t translate(key, fallback, params)
     * @param array{id:int,from_name:string,subject:?string,date_received:string}[] $participatedRows
     *     Messaging Evolution Phase 1 — broader "conversation you
     *     participated in" activity, hydrated via
     *     {@see \BinktermPHP\Messaging\SylcHydrator::hydrateEchomailParticipated()}.
     *     Appended last (default `[]`) to keep the pre-Phase-1 4-argument
     *     call shape source-compatible.
     * @return array{
     *     quiet: bool,
     *     personal: list<array{type:'netmail'|'reply'|'thread',label:string}>,
     *     personalMore: bool,
     *     ambient: list<array{tag:string,count:int}>,
     *     ambientMore: bool,
     * }
     */
    public static function detail(ActivityPlan $plan, array $netmailRows, array $replyRows, callable $t, array $participatedRows = []): array
    {
        // Direct personal relevance outranks broader conversation activity
        // (human-approved precedence, Messaging Evolution Phase 1) — direct
        // items always fill the compact row budget before any thread-activity
        // row is considered, mirroring SylcPulse's Web-side precedence
        // exactly.
        $directItems = [];
        foreach ($netmailRows as $row) {
            $directItems[] = ['type' => 'netmail'] + $row;
        }
        foreach ($replyRows as $row) {
            $directItems[] = ['type' => 'reply'] + $row;
        }
        usort($directItems, static fn (array $a, array $b): int => $b['date_received'] <=> $a['date_received']);

        $threadItems = [];
        foreach ($participatedRows as $row) {
            $threadItems[] = ['type' => 'thread'] + $row;
        }
        usort($threadItems, static fn (array $a, array $b): int => $b['date_received'] <=> $a['date_received']);

        $items = array_merge($directItems, $threadItems);

        $personalTotal = self::personalCount($plan);
        $shown = array_slice($items, 0, self::MAX_PERSONAL_ROWS);
        $personalMore = $personalTotal > count($shown)
            || !empty($plan->personal['netmailTruncated'] ?? false)
            || !empty($plan->personal['repliesTruncated'] ?? false)
            || !empty($plan->personal['participatedTruncated'] ?? false);

        $personal = [];
        foreach ($shown as $item) {
            $from = TextBlock::ellipsize((string) $item['from_name'], 20);
            $subject = $item['subject'] !== null
                ? TextBlock::ellipsize((string) $item['subject'], 30)
                : '';
            $personal[] = [
                'type' => $item['type'],
                'label' => trim($from . ($subject !== '' ? ' - ' . $subject : '')),
            ];
        }

        $areas = $plan->ambient['areas'] ?? [];
        usort($areas, static fn (array $a, array $b): int => $b['sinceBoundaryCount'] <=> $a['sinceBoundaryCount']);
        $ambientTotal = count($areas);
        $ambientShown = array_slice($areas, 0, self::MAX_AMBIENT_AREAS);
        $ambientMore = $ambientTotal > count($ambientShown) || !empty($plan->ambient['areasTruncated'] ?? false);

        $ambient = [];
        foreach ($ambientShown as $area) {
            $ambient[] = ['tag' => (string) $area['tag'], 'count' => (int) $area['sinceBoundaryCount']];
        }

        return [
            'quiet' => $personal === [] && $ambient === [],
            'personal' => $personal,
            'personalMore' => $personalMore,
            'ambient' => $ambient,
            'ambientMore' => $ambientMore,
        ];
    }

    private static function personalCount(ActivityPlan $plan): int
    {
        return count($plan->personal['netmailIds'] ?? [])
            + count($plan->personal['replyIds'] ?? [])
            + count($plan->personal['participatedIds'] ?? []);
    }
}
