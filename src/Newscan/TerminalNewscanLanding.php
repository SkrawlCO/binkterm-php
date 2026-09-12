<?php

namespace BinktermPHP\Newscan;

/**
 * Presentation-only projection of a {@see NewscanPlan} into the strings the
 * authored terminal **Messages** landing shows: the overall "waiting for you"
 * STATUS block, and the per-destination MENU annotations.
 *
 * Like {@see WebNewscanSummary} this never queries, never advances a watermark,
 * and never derives read rules — it only formats numbers that
 * {@see UnifiedNewscanService::plan()} already resolved from canonical read
 * state. Building it marks nothing read.
 *
 * The distinctions the recon fixed are preserved and never merged into one
 * number: netmail is *unread*, echomail is *new since the per-area watermark*
 * plus an area count, bulletins are an *unread count*. When the plan hit a cap
 * ({@see NewscanPlan::$truncated}) the STATUS block says so on its own line
 * rather than presenting a capped figure as complete.
 */
final class TerminalNewscanLanding
{
    /** Badge signal names the navigation `presentation.badge` hints reference. */
    public const BADGE_NETMAIL   = 'messages.netmail_unread';
    public const BADGE_ECHOMAIL  = 'messages.echomail_new';
    public const BADGE_BULLETINS = 'messages.bulletins_new';

    /**
     * Front Door signal for the root `[M] Messages` item. Rendered INLINE on
     * that item's own menu row (`presentation.badge_inline = true` on the
     * `messages` root item — see {@see PresentationHints}), not swept into the
     * ambient "AROUND THE BOARD" activity line the way Crossroads/People
     * badges are: message state reads as actionable navigation state, not
     * ambient board activity.
     *
     * Deliberately NOT a simple sum of all three Newscan counts: netmail
     * (private, addressed to the caller) and bulletins (sysop announcements)
     * are genuinely personal and are combined into one "waiting" figure, but
     * echomail is public network volume that scales with subscribed-area
     * traffic, not a personal backlog — merging it in would let "N waiting"
     * misrepresent thousands of public postings as things owed to the caller.
     * echomail is reported separately as "new echo". At zero this signal is
     * OMITTED entirely (like the other per-destination badges) — a permanent
     * "All caught up" on every quiet visit reads as clutter, not information.
     */
    public const BADGE_SUMMARY = 'messages.summary_badge';

    /** Middle dot separator for the joined STATUS sentence. */
    private const SEP = " \u{00B7} ";

    /**
     * @param NewscanPlan $plan
     * @param callable(string,string,array<string,int|string>):string $t
     *        (key, English fallback, params) -> localized text
     * @return array{summary:array<int,string>,badges:array<string,string>}
     *         `summary` is exactly two lines (line 2 empty unless truncated or
     *         caught up); `badges` omits every zero-value entry, including
     *         {@see BADGE_SUMMARY} when nothing is waiting and nothing is new.
     */
    public static function project(NewscanPlan $plan, callable $t): array
    {
        $netmail   = $plan->netmailCount();
        $echomail  = $plan->echomailCount();
        $areas     = $plan->areaCount();
        $bulletins = max(0, $plan->bulletinUnread);

        $badges = [];
        if ($netmail > 0) {
            $badges[self::BADGE_NETMAIL] = $t(
                'ui.terminalserver.messages.badge.netmail',
                '{count} unread',
                ['count' => $netmail]
            );
        }
        if ($echomail > 0) {
            $badges[self::BADGE_ECHOMAIL] = $t(
                'ui.terminalserver.messages.badge.echomail',
                '{count} new, {areas} area(s)',
                ['count' => $echomail, 'areas' => $areas]
            );
        }
        if ($bulletins > 0) {
            $badges[self::BADGE_BULLETINS] = $t(
                'ui.terminalserver.messages.badge.bulletins',
                '{count} new',
                ['count' => $bulletins]
            );
        }

        $frontDoor = self::frontDoorSignal($netmail + $bulletins, $echomail, $t);
        if ($frontDoor !== null) {
            $badges[self::BADGE_SUMMARY] = $frontDoor;
        }

        if ($plan->isEmpty()) {
            return [
                'summary' => [
                    $t('ui.terminalserver.messages.landing.caught_up', 'You\'re all caught up.', []),
                    '',
                ],
                'badges' => $badges,
            ];
        }

        $fragments = [];
        if ($netmail > 0) {
            $fragments[] = $t(
                'ui.terminalserver.messages.landing.netmail',
                '{count} unread netmail',
                ['count' => $netmail]
            );
        }
        if ($echomail > 0) {
            $fragments[] = $t(
                'ui.terminalserver.messages.landing.echomail',
                '{count} new echomail in {areas} area(s)',
                ['count' => $echomail, 'areas' => $areas]
            );
        }
        if ($bulletins > 0) {
            $fragments[] = $t(
                'ui.terminalserver.messages.landing.bulletins',
                '{count} unread bulletin(s)',
                ['count' => $bulletins]
            );
        }

        return [
            'summary' => [
                implode(self::SEP, $fragments),
                $plan->truncated
                    ? $t(
                        'ui.terminalserver.messages.landing.truncated',
                        'More new messages remain beyond the scan limit.',
                        []
                    )
                    : '',
            ],
            'badges' => $badges,
        ];
    }

    /**
     * The Front Door's three positive states, or null at zero (omitted
     * entirely — no menu-row annotation, matching every other per-destination
     * badge). Never merges echomail into "waiting" (public network volume is
     * not a personal backlog). Each positive state is one fully translated
     * sentence (no fragments assembled with a hardcoded separator in PHP), and
     * neither word repeats "Messages" — the menu row already supplies that
     * noun.
     *
     * @param int $personalWaiting unread netmail + unread bulletins
     * @param int $newEcho new echomail (never the area count)
     * @param callable(string,string,array<string,int|string>):string $t
     */
    private static function frontDoorSignal(int $personalWaiting, int $newEcho, callable $t): ?string
    {
        if ($personalWaiting > 0 && $newEcho > 0) {
            return $t(
                'ui.terminalserver.messages.badge.front_door_waiting_and_echo',
                "{waiting} waiting \u{00B7} {echo} new echo",
                ['waiting' => $personalWaiting, 'echo' => $newEcho]
            );
        }
        if ($personalWaiting > 0) {
            return $t(
                'ui.terminalserver.messages.badge.front_door_waiting',
                '{waiting} waiting',
                ['waiting' => $personalWaiting]
            );
        }
        if ($newEcho > 0) {
            return $t(
                'ui.terminalserver.messages.badge.front_door_echo',
                '{echo} new echo',
                ['echo' => $newEcho]
            );
        }

        return null;
    }
}
