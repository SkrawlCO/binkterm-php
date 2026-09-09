<?php

namespace BinktermPHP\Terminal;

use BinktermPHP\UserMeta;

/**
 * Per-user terminal mail/chat browser state: the page positions, folder, sort
 * order and chat target the telnet/SSH message viewers remember between
 * sessions. Backed by {@see UserMeta} under the `terminal_*` keys.
 *
 * This is the canonical owner of that state and its validation. The
 * `GET|POST /api/user/terminal-mail-state` routes are thin adapters over it
 * (the endpoint has no web callers — only the terminal daemons use it), and the
 * terminal handlers call it directly to avoid a localhost HTTP round trip per
 * list navigation / message open.
 */
final class TerminalMailState
{
    /** Keys stored as positive integers (or cleared). */
    private const INT_KEYS = [
        'terminal_netmail_page',
        'terminal_netmail_selected_message_id',
        'terminal_echomail_areas_page',
    ];

    private const SORTS = ['date_desc', 'date_asc', 'subject', 'author'];

    private const POSITIONS_MAX_BYTES = 64000;

    private UserMeta $meta;

    public function __construct(?UserMeta $meta = null)
    {
        $this->meta = $meta ?? new UserMeta();
    }

    /**
     * Every terminal state key for a user, raw (the string/JSON form as stored).
     * Shape matches the `settings` object the GET route returns.
     *
     * @return array<string,?string>
     */
    public function load(int $userId): array
    {
        $keys = [
            'terminal_netmail_page',
            'terminal_netmail_selected_message_id',
            'terminal_netmail_folder',
            'terminal_netmail_sort',
            'terminal_echomail_areas_page',
            'terminal_echomail_positions',
            'terminal_echomail_sort',
            'terminal_chat_target',
        ];

        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->meta->getValue($userId, $key);
        }

        return $out;
    }

    /**
     * Apply a partial settings map, running exactly the validation the POST
     * route enforced. Unknown keys are ignored. On the first invalid value
     * nothing further is written and `ok` is false (same as the route's early
     * 400 return).
     *
     * @param array<string,mixed> $settings
     * @return array{ok:bool,error:?string}
     */
    public function save(int $userId, array $settings): array
    {
        foreach (self::INT_KEYS as $key) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }
            $value = $settings[$key];
            if ($value === null || $value === '') {
                $this->meta->setValue($userId, $key, null);
                continue;
            }
            if (!is_numeric($value) || (int) $value < 1) {
                return self::fail("Invalid value for {$key}");
            }
            $this->meta->setValue($userId, $key, (string) ((int) $value));
        }

        if (array_key_exists('terminal_echomail_positions', $settings)) {
            $positions = $settings['terminal_echomail_positions'];
            if (is_string($positions)) {
                $decoded = json_decode($positions, true);
                if (!is_array($decoded)) {
                    return self::fail('Invalid value for terminal_echomail_positions');
                }
                $positions = $decoded;
            }
            if (!is_array($positions)) {
                return self::fail('Invalid value for terminal_echomail_positions');
            }

            $clean = [];
            foreach ($positions as $area => $entry) {
                if (!is_string($area) || trim($area) === '' || strlen($area) > 128 || !is_array($entry)) {
                    continue;
                }
                $page = (int) ($entry['page'] ?? 1);
                if ($page < 1) {
                    $page = 1;
                }
                $selected = $entry['selected_message_id'] ?? null;
                if ($selected !== null) {
                    $selected = (!is_numeric($selected) || (int) $selected < 1) ? null : (int) $selected;
                }
                $clean[$area] = ['page' => $page, 'selected_message_id' => $selected];
            }

            $encoded = json_encode($clean);
            if ($encoded === false || strlen($encoded) > self::POSITIONS_MAX_BYTES) {
                return self::fail('Invalid value for terminal_echomail_positions');
            }
            $this->meta->setValue($userId, 'terminal_echomail_positions', $encoded);
        }

        // Order below mirrors the POST route so the "first invalid value" that
        // is reported is identical for a payload with several bad fields.
        $sortResult = $this->applySort($userId, $settings, 'terminal_echomail_sort');
        if (!$sortResult['ok']) {
            return $sortResult;
        }

        if (array_key_exists('terminal_netmail_folder', $settings)) {
            $folder = $settings['terminal_netmail_folder'];
            if ($folder === null || $folder === '') {
                $this->meta->setValue($userId, 'terminal_netmail_folder', null);
            } elseif (in_array($folder, ['inbox', 'sent'], true)) {
                $this->meta->setValue($userId, 'terminal_netmail_folder', $folder);
            } else {
                return self::fail('Invalid value for terminal_netmail_folder');
            }
        }

        $sortResult = $this->applySort($userId, $settings, 'terminal_netmail_sort');
        if (!$sortResult['ok']) {
            return $sortResult;
        }

        if (array_key_exists('terminal_chat_target', $settings)) {
            $target = $settings['terminal_chat_target'];
            if ($target === null || $target === '') {
                $this->meta->setValue($userId, 'terminal_chat_target', null);
            } else {
                if (is_string($target)) {
                    $decoded = json_decode($target, true);
                    if (!is_array($decoded)) {
                        return self::fail('Invalid value for terminal_chat_target');
                    }
                    $target = $decoded;
                }
                if (!is_array($target)) {
                    return self::fail('Invalid value for terminal_chat_target');
                }

                $type  = (string) ($target['type'] ?? '');
                $id    = (int) ($target['id'] ?? 0);
                $label = trim((string) ($target['label'] ?? ''));
                if (($type !== 'room' && $type !== 'dm') || $id < 1 || $label === '' || strlen($label) > 255) {
                    return self::fail('Invalid value for terminal_chat_target');
                }

                $encoded = json_encode(['type' => $type, 'id' => $id, 'label' => $label]);
                if ($encoded === false) {
                    return self::fail('Invalid value for terminal_chat_target');
                }
                $this->meta->setValue($userId, 'terminal_chat_target', $encoded);
            }
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{ok:bool,error:?string}
     */
    private function applySort(int $userId, array $settings, string $key): array
    {
        if (!array_key_exists($key, $settings)) {
            return ['ok' => true, 'error' => null];
        }
        $sort = $settings[$key];
        if ($sort === null || $sort === '') {
            $this->meta->setValue($userId, $key, null);
        } elseif (in_array($sort, self::SORTS, true)) {
            $this->meta->setValue($userId, $key, $sort);
        } else {
            return self::fail("Invalid value for {$key}");
        }

        return ['ok' => true, 'error' => null];
    }

    /** @return array{ok:false,error:string} */
    private static function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error];
    }
}
