<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\BbsDirectory;
use BinktermPHP\Database;
use BinktermPHP\Terminal\Presentation\TerminalHyperlink;

/**
 * BbsListHandler — BBS directory browser for the terminal server.
 *
 * Displays the active BBS directory entries in a paginated list. Users can
 * browse entries, view details, and return to the main menu. Data is read
 * directly from the database (same approach as InterestsHandler) to avoid
 * HTTP cookie-auth fragility from the server process.
 */
class BbsListHandler
{
    private BbsSession $server;
    private string $apiBase;

    public function __construct(BbsSession $server, string $apiBase)
    {
        $this->server  = $server;
        $this->apiBase = $apiBase;
    }

    public function show($conn, array &$state, string $session): void
    {
        $locale  = $state['locale'];
        $shell   = TerminalShellFactory::create($this->server, $state);
        $perPage = max(5, ($state['rows'] ?? 24) - 3);
        $page    = 1;

        $entries = $this->fetchEntries();

        if (empty($entries)) {
            $shell->showText(
                $conn,
                $state,
                $this->server->t(
                    'ui.terminalserver.bbslist.title',
                    'BBS Directory ({total} systems)',
                    ['total' => 0],
                    $locale
                ),
                [$this->server->t('ui.terminalserver.bbslist.empty', 'No BBS listings available.', [], $locale)]
            );
            return;
        }

        $total      = count($entries);
        $totalPages = (int)ceil($total / $perPage);

        $statusBar = [
            ['text' => 'U/D',       'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Move  ',   'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'L/R',       'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Page  ',   'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Enter',     'color' => TelnetUtils::ANSI_RED],
            ['text' => ' View  ',   'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Q',         'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Quit',     'color' => TelnetUtils::ANSI_BLUE],
        ];

        $selectedIndex = 0;

        while (true) {
            $page  = max(1, min($page, $totalPages));
            $slice = array_slice($entries, ($page - 1) * $perPage, $perPage);

            $cols      = max(40, (int)($state['cols'] ?? 80));
            $nameWidth = max(20, (int)floor($cols * 0.35));
            $hostWidth = max(20, (int)floor($cols * 0.30));

            $rows = [];
            foreach ($slice as $entry) {
                $name     = $this->truncate((string)($entry['name'] ?? ''), $nameWidth);
                $location = (string)($entry['location'] ?? '');
                $host     = (string)($entry['telnet_host'] ?? '');
                $port     = (int)($entry['telnet_port'] ?? 23);
                $address  = $host !== '' ? ($port !== 23 ? "{$host}:{$port}" : $host) : '';
                $address  = $this->truncate($address, $hostWidth);

                $line = sprintf(
                    ' %s  %s',
                    TelnetUtils::colorize(str_pad($name, $nameWidth), TelnetUtils::ANSI_CYAN),
                    TelnetUtils::colorize($address, TelnetUtils::ANSI_DIM)
                );
                if ($location !== '') {
                    $line .= '  ' . TelnetUtils::colorize($location, TelnetUtils::ANSI_DIM);
                }
                $rows[] = $line;
            }

            $title = TelnetUtils::colorize(
                $this->server->t(
                    'ui.terminalserver.bbslist.title',
                    'BBS Directory ({total} systems)',
                    ['total' => $total],
                    $locale
                ),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            );

            $result        = $shell->showSelectableList($conn, $state, $title, $rows, $page, $totalPages, $selectedIndex, $statusBar);
            $selectedIndex = $result['selectedIndex'];

            switch ($result['action']) {
                case 'disconnect':
                    return;
                case 'quit':
                    return;
                case 'prev':
                    $page--;
                    $selectedIndex = 0;
                    break;
                case 'next':
                    $page++;
                    $selectedIndex = 0;
                    break;
                case 'select':
                    $entryIndex = ($page - 1) * $perPage + $result['index'];
                    if (isset($entries[$entryIndex])) {
                        $this->server->logAction($state['username'] ?? 'unknown', 'BBS List: viewed "' . ($entries[$entryIndex]['name'] ?? '') . '"');
                        $this->showDetail($conn, $state, $entries[$entryIndex], $shell);
                    }
                    break;
            }
        }
    }

    private function showDetail($conn, array &$state, array $entry, TerminalShellInterface $shell): void
    {
        $locale = $state['locale'];
        $cols   = max(40, (int)($state['cols'] ?? 80));
        $lines  = [];

        $fields = [
            ['ui.terminalserver.bbslist.detail.sysop',    'Sysop',    $entry['sysop'] ?? ''],
            ['ui.terminalserver.bbslist.detail.location',  'Location', $entry['location'] ?? ''],
            ['ui.terminalserver.bbslist.detail.os',        'OS',       $entry['os'] ?? ''],
        ];

        $host = (string)($entry['telnet_host'] ?? '');
        $port = (int)($entry['telnet_port'] ?? 23);
        if ($host !== '') {
            $address = $port !== 23 ? "{$host}:{$port}" : $host;
            $fields[] = ['ui.terminalserver.bbslist.detail.telnet', 'Telnet', $address];
        }
        $labelWidth = 10;

        if (!empty($entry['website'])) {
            // The `website` field is only admin-approved (bbs_directory
            // status='active'), never independently URL-validated on write —
            // TerminalHyperlink does its own strict validation and falls
            // back to the identical plain-text value (no OSC 8) on anything
            // that isn't a clean http/https URL. Label and target are the
            // same string, so there is no separate visible-vs-actual URL
            // for a caller to be misled by.
            //
            // The line-shell's writeWrapped() word-wraps this row with plain
            // byte counting (no OSC/ANSI awareness), so TerminalHyperlink is
            // asked to only wrap when the result provably fits this
            // connection's known column budget — otherwise no shell's
            // wrapping could ever need to break the escape sequence
            // mid-stream. This keeps BbsListHandler shell-agnostic (a width
            // budget, not a branch on which shell is active).
            $website     = (string)$entry['website'];
            $prefixWidth = 2 + $labelWidth + 1 + 1; // '  ' + padded 'Label:' + ' '
            $margin      = 4;

            $fields[] = [
                'ui.terminalserver.bbslist.detail.website',
                'Website',
                TerminalHyperlink::wrapIfFits($website, $website, $cols - $prefixWidth - $margin),
            ];
        }
        foreach ($fields as [$key, $defaultLabel, $value]) {
            if ((string)$value === '') {
                continue;
            }
            $label = $this->server->t($key, $defaultLabel, [], $locale);
            $lines[] = sprintf(
                '  %s %s',
                TelnetUtils::colorize(str_pad($label . ':', $labelWidth + 1), TelnetUtils::ANSI_BOLD),
                $value
            );
        }

        if (!empty($entry['notes'])) {
            $lines[] = '';
            foreach (TelnetUtils::wrapTextLines((string)$entry['notes'], max(20, $cols - 4)) as $line) {
                $lines[] = $line;
            }
        }

        $shell->showText(
            $conn,
            $state,
            (string)($entry['name'] ?? ''),
            $lines
        );
    }

    private function fetchEntries(): array
    {
        $db        = Database::getInstance()->getPdo();
        $directory = new BbsDirectory($db);
        return $directory->getActiveEntries();
    }

    private function truncate(string $str, int $max): string
    {
        if (mb_strlen($str) <= $max) {
            return $str;
        }
        return mb_substr($str, 0, $max - 1) . '…';
    }
}
