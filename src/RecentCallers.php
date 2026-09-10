<?php

namespace BinktermPHP;

use BinktermPHP\I18n\Translator;
use BinktermPHP\Terminal\Presentation\TextBlock;

/** Public identity and approximate arrival only, shared by Web and terminal. */
final class RecentCallers
{
    /** @return array<array{username:string,presence:string,is_online:bool}> */
    public static function present(array $rows, string $locale = 'en', ?int $now = null): array
    {
        $now ??= time();
        $translator = new Translator();
        $t = static fn(string $key, array $params = []): string => $translator->translate($key, $params, $locale, ['common']);
        $result = [];
        foreach (array_slice($rows, 0, 6) as $row) {
            $online = in_array($row['is_online'] ?? false, [true, 1, '1', 't'], true);
            $age = max(0, $now - (new \DateTimeImmutable($row['last_caller_visit_at'], new \DateTimeZone('UTC')))->getTimestamp());
            $presence = match (true) {
                $online => $t('ui.dashboard.callers_online_now'),
                $age < 60 => $t('time.just_now'),
                $age < 3600 => $t('ui.echolist.time.minutes_ago', ['count' => intdiv($age, 60)]),
                $age < 86400 => $t('ui.echolist.time.hours_ago', ['count' => intdiv($age, 3600)]),
                $age < 172800 => $t('time.yesterday'),
                default => $t('ui.echolist.time.days_ago', ['count' => intdiv($age, 86400)]),
            };
            $result[] = ['username' => (string)$row['username'], 'presence' => $presence, 'is_online' => $online];
        }
        return $result;
    }

    /** One bounded ambient line; renderer owns charset, color and placement. */
    public static function terminalLine(array $entries, string $locale = 'en', int $width = 72): ?string
    {
        if ($entries === []) {
            return null;
        }
        $title = (new Translator())->translate('ui.recent_callers.title', [], $locale, ['common']);
        $parts = [];
        foreach (array_slice($entries, 0, 2) as $entry) {
            $username = preg_replace('/[\p{C}]/u', '', $entry['username']) ?? '';
            $parts[] = TextBlock::ellipsize($username, 16) . ' (' . $entry['presence'] . ')';
        }
        return TextBlock::ellipsize($title . ': ' . implode(', ', $parts), max(8, $width));
    }
}
