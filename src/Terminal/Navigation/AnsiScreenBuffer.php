<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * A tiny fixed-size terminal screen model: feed it the byte stream a themed
 * render produces (absolute cursor moves, SGR, printable text, erases) and read
 * back a flat grid of rows with their SGR runs preserved.
 *
 * This exists so the F6 browser preview can show the themed presentation
 * faithfully: the live terminal interprets `ESC [ r;c H`, but the preview pane's
 * SGR-only ANSI->HTML pass cannot, so the server resolves the positioning into
 * a grid first. It is not a general terminal emulator — it understands only the
 * sequences the themed renderer and the sanitised templates emit.
 */
final class AnsiScreenBuffer
{
    /** @var array<int,array<int,string>> row-major grid of single characters */
    private array $cells;
    /** @var array<int,array<int,string>> SGR string in effect for each cell */
    private array $sgr;

    private int $cursorRow = 1;
    private int $cursorCol = 1;
    /** @var array<int,string> active SGR codes, in application order */
    private array $sgrCodes = [];

    public function __construct(
        private readonly int $cols = 80,
        private readonly int $rows = 24,
    ) {
        $this->cells = array_fill(1, max(1, $rows), array_fill(1, max(1, $cols), ' '));
        $this->sgr   = array_fill(1, max(1, $rows), array_fill(1, max(1, $cols), ''));
    }

    public function write(string $bytes): self
    {
        $len = strlen($bytes);
        $i = 0;
        while ($i < $len) {
            $ch = $bytes[$i];

            if ($ch === "\x1b" && $i + 1 < $len && $bytes[$i + 1] === '[') {
                $i = $this->consumeCsi($bytes, $i, $len);
                continue;
            }
            if ($ch === "\x1b") {
                // A bare / non-CSI escape: the sanitiser removes these, but be
                // defensive and skip the introducer plus one byte.
                $i += 2;
                continue;
            }
            if ($ch === "\r") {
                $this->cursorCol = 1;
                $i++;
                continue;
            }
            if ($ch === "\n") {
                $this->cursorRow++;
                $i++;
                continue;
            }
            if ($ch === "\t") {
                $this->cursorCol = min($this->cols + 1, (intdiv($this->cursorCol - 1, 8) + 1) * 8 + 1);
                $i++;
                continue;
            }
            if ($ch < ' ') {
                $i++;
                continue;
            }

            // A printable character, possibly multi-byte UTF-8.
            $clen = $this->utf8Len($ch);
            $glyph = substr($bytes, $i, $clen);
            $this->putGlyph($glyph);
            $i += $clen;
        }

        return $this;
    }

    /**
     * @return array<int,string> $rows lines, 1-based order, SGR runs re-emitted,
     *                           trailing spaces kept so callers can see the box
     */
    public function toLines(): array
    {
        $out = [];
        for ($r = 1; $r <= $this->rows; $r++) {
            $line = '';
            $active = '';
            for ($c = 1; $c <= $this->cols; $c++) {
                $cellSgr = $this->sgr[$r][$c] ?? '';
                if ($cellSgr !== $active) {
                    $line .= "\x1b[0m" . $cellSgr;
                    $active = $cellSgr;
                }
                $line .= $this->cells[$r][$c] ?? ' ';
            }
            if ($active !== '') {
                $line .= "\x1b[0m";
            }
            $out[] = rtrim($line, ' ');
        }

        return $out;
    }

    private function putGlyph(string $glyph): void
    {
        if ($this->cursorRow >= 1 && $this->cursorRow <= $this->rows
            && $this->cursorCol >= 1 && $this->cursorCol <= $this->cols) {
            $this->cells[$this->cursorRow][$this->cursorCol] = $glyph;
            $this->sgr[$this->cursorRow][$this->cursorCol]   = $this->currentSgr();
        }
        $this->cursorCol++;
    }

    private function consumeCsi(string $bytes, int $start, int $len): int
    {
        // ESC [ <params> <final>
        $j = $start + 2;
        $params = '';
        while ($j < $len && strpos('0123456789;:?<>=', $bytes[$j]) !== false) {
            $params .= $bytes[$j];
            $j++;
        }
        while ($j < $len && $bytes[$j] >= ' ' && $bytes[$j] <= '/') {
            $j++; // intermediate bytes
        }
        if ($j >= $len) {
            return $len;
        }
        $final = $bytes[$j];
        $j++;

        $nums = array_map('intval', $params === '' ? [] : explode(';', str_replace(['?', ':', '<', '>', '='], '', $params)));

        switch ($final) {
            case 'm':
                $this->applySgr($params);
                break;
            case 'H':
            case 'f':
                $this->cursorRow = $this->clamp($nums[0] ?? 1, 1, $this->rows);
                $this->cursorCol = $this->clamp($nums[1] ?? 1, 1, $this->cols + 1);
                break;
            case 'A':
                $this->cursorRow = $this->clamp($this->cursorRow - max(1, $nums[0] ?? 1), 1, $this->rows);
                break;
            case 'B':
                $this->cursorRow = $this->clamp($this->cursorRow + max(1, $nums[0] ?? 1), 1, $this->rows);
                break;
            case 'C':
                $this->cursorCol = $this->clamp($this->cursorCol + max(1, $nums[0] ?? 1), 1, $this->cols + 1);
                break;
            case 'D':
                $this->cursorCol = $this->clamp($this->cursorCol - max(1, $nums[0] ?? 1), 1, $this->cols + 1);
                break;
            case 'J':
                $this->eraseDisplay($nums[0] ?? 0);
                break;
            case 'K':
                $this->eraseLine($nums[0] ?? 0);
                break;
            default:
                // ignore everything else (the sanitiser removes most of it)
                break;
        }

        return $j;
    }

    private function applySgr(string $params): void
    {
        $codes = explode(';', $params === '' ? '0' : $params);
        foreach ($codes as $code) {
            $code = trim($code);
            if ($code === '' || $code === '0') {
                $this->sgrCodes = [];
                continue;
            }
            $this->sgrCodes[] = $code;
        }
    }

    private function currentSgr(): string
    {
        return $this->sgrCodes === [] ? '' : "\x1b[" . implode(';', $this->sgrCodes) . 'm';
    }

    private function eraseDisplay(int $mode): void
    {
        $blank = static function (array &$row): void {
            foreach ($row as $k => $_) {
                $row[$k] = ' ';
            }
        };
        if ($mode === 2 || $mode === 3) {
            for ($r = 1; $r <= $this->rows; $r++) {
                $blank($this->cells[$r]);
                foreach ($this->sgr[$r] as $k => $_) {
                    $this->sgr[$r][$k] = '';
                }
            }

            return;
        }
        // 0 = cursor to end, 1 = start to cursor: approximate by clearing whole
        // rows from/to the cursor row (sufficient for our render pattern).
        $range = $mode === 1 ? range(1, $this->cursorRow) : range($this->cursorRow, $this->rows);
        foreach ($range as $r) {
            $blank($this->cells[$r]);
        }
    }

    private function eraseLine(int $mode): void
    {
        $r = $this->cursorRow;
        if ($r < 1 || $r > $this->rows) {
            return;
        }
        $from = $mode === 1 ? 1 : ($mode === 2 ? 1 : $this->cursorCol);
        $to   = $mode === 0 ? $this->cols : ($mode === 2 ? $this->cols : $this->cursorCol);
        for ($c = $from; $c <= $to; $c++) {
            $this->cells[$r][$c] = ' ';
            $this->sgr[$r][$c]   = '';
        }
    }

    private function clamp(int $v, int $lo, int $hi): int
    {
        return max($lo, min($hi, $v));
    }

    private function utf8Len(string $lead): int
    {
        $b = ord($lead);
        if ($b >= 0xF0) {
            return 4;
        }
        if ($b >= 0xE0) {
            return 3;
        }
        if ($b >= 0xC0) {
            return 2;
        }

        return 1;
    }
}
