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

    /** Middle dot separator for the joined STATUS sentence. */
    private const SEP = " \u{00B7} ";

    /**
     * @param NewscanPlan $plan
     * @param callable(string,string,array<string,int|string>):string $t
     *        (key, English fallback, params) -> localized text
     * @return array{summary:array<int,string>,badges:array<string,string>}
     *         `summary` is exactly two lines (line 2 empty unless truncated or
     *         caught up); `badges` omits every zero-value entry.
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
}
