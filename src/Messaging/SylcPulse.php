<?php

declare(strict_types=1);

namespace BinktermPHP\Messaging;

/**
 * View model for the dashboard "Since Your Last Call" card.
 *
 * A pure reducer over already-fetched, already-authorized reads — mirroring
 * {@see \BinktermPHP\Crossroads\DashboardPulse}'s exact discipline. It
 * performs no queries and no authorization of its own: `$plan` comes from
 * {@see ActivityService::plan()} (ids/counts, already visibility-filtered),
 * and `$netmailRows`/`$replyRows` come from {@see SylcHydrator} (display
 * fields for a small, already-selected subset of those same ids).
 *
 * Personal relevance outranks ambient activity (human-approved principle):
 * the composed `personal` list is never truncated in favor of `ambient`, and
 * the partial renders personal above ambient regardless of which is larger.
 *
 * `hasPreviousVisit === false` is not a state this class ever renders —
 * {@see shouldCompose()} (and the caller) must not invoke {@see compose()} at
 * all in that case, so there is no "first call" render path to get wrong.
 */
final class SylcPulse
{
    public const MAX_PERSONAL_ROWS = 5;
    public const MAX_AMBIENT_ROWS  = 5;

    /**
     * Whether the `/` route should spend an ActivityService read composing
     * the pulse: only when the card is available AND the viewer has not
     * hidden it. Mirrors {@see \BinktermPHP\Crossroads\DashboardPulse::shouldCompose()}.
     *
     * @param array{hidden?:array<int,string>} $dashboardLayout
     */
    public static function shouldCompose(array $dashboardLayout): bool
    {
        return !in_array('since_last_call', $dashboardLayout['hidden'] ?? [], true);
    }

    /**
     * @param array{id:int,from_name:string,subject:?string,date_received:string}[] $netmailRows
     *     {@see SylcHydrator::hydrateNetmail()} result for (at most) the most
     *     recent MAX_PERSONAL_ROWS of `$plan->personal['netmailIds']`.
     * @param array{id:int,from_name:string,subject:?string,date_received:string}[] $replyRows
     *     {@see SylcHydrator::hydrateEchomailReplies()} result for (at most)
     *     the most recent MAX_PERSONAL_ROWS of `$plan->personal['replyIds']`.
     * @return array{
     *     state: 'quiet'|'active',
     *     personal: list<array{type:'netmail'|'reply',id:int,from_name:string,subject:?string,date_received:string}>,
     *     personal_more: bool,
     *     ambient: list<array{echoareaId:int,tag:string,domain:string,isLocal:bool,sinceBoundaryCount:int}>,
     *     ambient_more: bool,
     * }
     */
    public static function compose(ActivityPlan $plan, array $netmailRows, array $replyRows): array
    {
        $personalSource = $plan->personal ?? ['netmailIds' => [], 'netmailTruncated' => false, 'replyIds' => [], 'repliesTruncated' => false];
        $ambientSource  = $plan->ambient  ?? ['areas' => [], 'areasTruncated' => false];

        $items = [];
        foreach ($netmailRows as $row) {
            $items[] = ['type' => 'netmail'] + $row;
        }
        foreach ($replyRows as $row) {
            $items[] = ['type' => 'reply'] + $row;
        }
        // Most recent first — both id lists arrive oldest-first from
        // ActivityService, and only a small tail of each was hydrated, so
        // sorting this already-small candidate set is cheap and exact.
        usort($items, static fn (array $a, array $b): int => $b['date_received'] <=> $a['date_received']);

        $personalTotal = count($personalSource['netmailIds']) + count($personalSource['replyIds']);
        $personal = array_slice($items, 0, self::MAX_PERSONAL_ROWS);
        $personalMore = $personalTotal > count($personal)
            || !empty($personalSource['netmailTruncated'])
            || !empty($personalSource['repliesTruncated']);

        $areas = $ambientSource['areas'];
        usort($areas, static fn (array $a, array $b): int => $b['sinceBoundaryCount'] <=> $a['sinceBoundaryCount']);
        $ambientTotal = count($areas);
        $ambient = array_slice($areas, 0, self::MAX_AMBIENT_ROWS);
        $ambientMore = $ambientTotal > count($ambient) || !empty($ambientSource['areasTruncated']);

        return [
            'state'         => ($personal === [] && $ambient === []) ? 'quiet' : 'active',
            'personal'      => $personal,
            'personal_more' => $personalMore,
            'ambient'       => $ambient,
            'ambient_more'  => $ambientMore,
        ];
    }
}
