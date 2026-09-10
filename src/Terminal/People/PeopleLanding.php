<?php

namespace BinktermPHP\Terminal\People;

/**
 * Presentation-only projection of existing authoritative presence state into
 * the two lines the authored terminal **People** landing shows in its STATUS
 * region:
 *
 *   line 1 — who is around RIGHT NOW (live), or an honest "quiet" statement
 *   line 2 — who was here RECENTLY (historical), from the same Recent Callers
 *            source the front door already uses
 *
 * The two are never blurred: line 1 is strictly the live presence snapshot,
 * line 2 is strictly historical, each self-labelled. A recent caller is never
 * implied to still be online.
 *
 * Like {@see \BinktermPHP\Newscan\TerminalNewscanLanding} this never queries and
 * never writes — it only formats values the caller already resolved from
 * canonical state ({@see \BinktermPHP\Auth::getOnlineSessions()} and
 * {@see \BinktermPHP\TelnetServer\BbsSession::recentCallersLine()}). The caller
 * is responsible for excluding the viewer's own sessions and for passing only
 * caller-visible fields (username + intentional public activity); nothing here
 * exposes a field the roster caller did not already hand it.
 */
final class PeopleLanding
{
    /** Hard cap on names listed on the live line before the rest roll into "+N more". */
    private const MAX_NAMES = 3;

    /** Longest public-activity fragment kept beside a name. */
    private const MAX_ACTIVITY = 24;

    /**
     * @param array<int,array{name:string,activity?:string}> $online
     *        distinct callers visible to the viewer, the viewer's own sessions
     *        already removed. `activity` is the caller-visible public activity
     *        string ("Playing LORD", "in Crossroads") or absent/empty.
     * @param string|null $recentCallersLine
     *        the already-formatted, already-localized Recent Callers line
     *        ({@see \BinktermPHP\RecentCallers::terminalLine()}), or null when
     *        there is no recent-caller history.
     * @param callable(string,string,array<string,int|string>):string $t
     *        (key, English fallback, params) -> localized text
     * @param int $width  the STATUS region width the lines must fit
     * @return array{0:string,1:string} exactly two plain-text lines, in order
     */
    public static function project(
        array $online,
        ?string $recentCallersLine,
        callable $t,
        int $width = 72
    ): array {
        $width = max(16, $width);

        return [
            self::liveLine($online, $t, $width),
            self::recentLine($recentCallersLine, $width),
        ];
    }

    /**
     * Reduce raw {@see \BinktermPHP\Auth::getOnlineSessions()} rows to the
     * bounded roster the projection consumes: one entry per distinct user, the
     * viewer's own sessions removed, and ONLY the two caller-visible fields
     * carried forward — `username` and the intentional `public_activity`
     * string. A service name, IP address, internal `activity`, real name,
     * FTN address or last-seen timestamp is never read here, so it can never
     * reach the STATUS line.
     *
     * @param iterable<array<string,mixed>> $rows
     * @param int $selfId  the viewing user's id; their rows are excluded
     * @return array<int,array{name:string,activity:string}>
     */
    public static function fromSessions(iterable $rows, int $selfId): array
    {
        $seen   = [];
        $roster = [];
        foreach ($rows as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            if ($uid <= 0 || $uid === $selfId || isset($seen[$uid])) {
                continue;
            }
            $seen[$uid] = true;
            $roster[]   = [
                'name'     => (string) ($row['username'] ?? ''),
                'activity' => trim((string) ($row['public_activity'] ?? '')),
            ];
        }

        return $roster;
    }

    /**
     * Line 1: the live presence snapshot, or the honest quiet statement.
     *
     * @param array<int,array{name:string,activity?:string}> $online
     * @param callable(string,string,array<string,int|string>):string $t
     */
    private static function liveLine(array $online, callable $t, int $width): string
    {
        $tokens = [];
        foreach ($online as $entry) {
            $name = self::clean((string) ($entry['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $activity = self::clean((string) ($entry['activity'] ?? ''));
            if ($activity !== '') {
                $activity = self::truncate($activity, self::MAX_ACTIVITY);
                $tokens[] = $name . ' (' . $activity . ')';
            } else {
                $tokens[] = $name;
            }
        }

        if ($tokens === []) {
            return self::truncate(
                $t('ui.terminalserver.people.landing.quiet', 'The board is quiet right now.', []),
                $width
            );
        }

        $total = count($tokens);
        $shown = array_slice($tokens, 0, self::MAX_NAMES);

        // Drop names until the whole line fits, folding each dropped name into
        // the "+N more" count rather than clipping a name mid-word.
        while (true) {
            $hidden = $total - count($shown);
            $names  = implode(', ', $shown);
            $line   = $hidden > 0
                ? $t(
                    'ui.terminalserver.people.landing.online_more',
                    'Online now: {names} (+{count} more)',
                    ['names' => $names, 'count' => $hidden]
                )
                : $t(
                    'ui.terminalserver.people.landing.online',
                    'Online now: {names}',
                    ['names' => $names]
                );

            if (mb_strlen($line, 'UTF-8') <= $width || count($shown) <= 1) {
                return self::truncate($line, $width);
            }
            array_pop($shown);
        }
    }

    /** Line 2: the historical Recent Callers line, verbatim, or blank. */
    private static function recentLine(?string $recentCallersLine, int $width): string
    {
        $line = self::clean((string) ($recentCallersLine ?? ''));

        return $line === '' ? '' : self::truncate($line, $width);
    }

    /** Strip control / format characters and collapse runs of whitespace. */
    private static function clean(string $value): string
    {
        $value = preg_replace('/\p{C}/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Plain hard clip to `$width` UTF-8 characters — no ellipsis glyph. The
     * STATUS region is not a mustFit region, so a rare over-long value is
     * clipped by the renderer rather than dropping the authored frame; keeping
     * a single-glyph terminator out of the string avoids the CP437 "..."
     * three-cell expansion entirely (cf. commit d5272ef5).
     */
    private static function truncate(string $value, int $width): string
    {
        return mb_strlen($value, 'UTF-8') <= $width
            ? $value
            : rtrim(mb_substr($value, 0, $width, 'UTF-8'));
    }
}
