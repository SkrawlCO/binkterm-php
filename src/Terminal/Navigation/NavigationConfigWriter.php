<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * Validated, atomic write of a declarative terminal navigation definition.
 *
 * This is the privileged backend boundary the future sysop editor calls
 * (through the admin daemon — the web process cannot write `config/`). It is
 * NOT a general file-write primitive:
 *
 *   - the payload MUST parse and fully validate as a {@see NavigationDefinition}
 *     before anything is written;
 *   - the destination is constrained to a single regular `*.json` file directly
 *     inside a caller-supplied base directory (the admin daemon passes the repo
 *     `config/` directory and a fixed filename — it does not forward any
 *     `TERMINAL_NAV_CONFIG` override for writes);
 *   - the write is temp-file + fsync + atomic rename within that directory, so a
 *     failure at any point leaves the previous valid config untouched and there
 *     is never a partial/truncated destination;
 *   - a pre-existing symlink or non-regular file at the target is refused.
 *
 * No IPC, no daemon dependency — unit-testable on its own.
 */
final class NavigationConfigWriter
{
    private const BASENAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*\.json$/';

    public function __construct(private readonly ActionRegistry $actions)
    {
    }

    public static function default(): self
    {
        return new self(TerminalActionCatalog::defaultRegistry());
    }

    /**
     * @param string $json      the candidate definition (raw JSON text)
     * @param string $targetPath the file to write (must sit directly in $baseDir)
     * @param string $baseDir   the only directory writes are permitted in
     */
    public function write(string $json, string $targetPath, string $baseDir): NavigationWriteResult
    {
        // 1. Validate the payload as a full NavigationDefinition. Nothing is
        //    written unless it passes every check.
        $load = (new NavigationDefinitionLoader($this->actions))->fromJson($json, basename($targetPath));
        if (!$load->isOk()) {
            return NavigationWriteResult::invalid($targetPath, $load->errors());
        }

        // 2. Constrain the destination.
        $constraint = $this->checkDestination($targetPath, $baseDir);
        if ($constraint !== null) {
            return NavigationWriteResult::ioFailure($targetPath, $constraint[0], $constraint[1]);
        }

        // 3. Canonical serialisation: pretty-printed, unescaped slashes/unicode,
        //    single trailing newline. Key order is preserved (author intent /
        //    readable diffs), matching the other config writers in this project.
        $decoded   = json_decode($json, true);
        $canonical = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($canonical === false) {
            return NavigationWriteResult::ioFailure($targetPath, 'encode', 'could not canonicalise the definition');
        }
        $canonical .= "\n";

        // 4. Atomic write via a temp file in the same directory.
        $dir = dirname($targetPath);
        $tmp = $dir . '/.' . basename($targetPath) . '.' . bin2hex(random_bytes(8)) . '.tmp';

        $handle = @fopen($tmp, 'xb'); // x = fail if it already exists (no races)
        if ($handle === false) {
            return NavigationWriteResult::ioFailure($targetPath, 'io', "could not create a temp file in {$dir}");
        }

        try {
            if (@fwrite($handle, $canonical) !== strlen($canonical)) {
                throw new \RuntimeException('short write to temp file');
            }
            @fflush($handle);
            // fsync where available so the bytes are durable before the rename.
            if (function_exists('fsync')) {
                @fsync($handle);
            }
        } catch (\Throwable $e) {
            @fclose($handle);
            @unlink($tmp);

            return NavigationWriteResult::ioFailure($targetPath, 'io', $e->getMessage());
        }
        @fclose($handle);

        // Match the existing file's mode, else the project default for config.
        $mode = is_file($targetPath) ? (fileperms($targetPath) & 0777) : 0644;
        @chmod($tmp, $mode);

        if (!@rename($tmp, $targetPath)) {
            @unlink($tmp);

            return NavigationWriteResult::ioFailure($targetPath, 'io', 'atomic rename into place failed (previous config left intact)');
        }

        return NavigationWriteResult::success($targetPath, strlen($canonical), $canonical);
    }

    /**
     * @return array{0:string,1:string}|null  [code, message] on refusal, null if OK
     */
    private function checkDestination(string $targetPath, string $baseDir): ?array
    {
        if (!preg_match(self::BASENAME_PATTERN, basename($targetPath))) {
            return ['path_constraint', 'target filename must be a plain *.json name'];
        }

        $realBase = realpath($baseDir);
        if ($realBase === false || !is_dir($realBase)) {
            return ['path_constraint', "base directory does not exist: {$baseDir}"];
        }
        if (!is_writable($realBase)) {
            return ['io', "base directory is not writable: {$realBase}"];
        }

        $realParent = realpath(dirname($targetPath));
        if ($realParent === false || $realParent !== $realBase) {
            return ['path_constraint', 'target must sit directly inside the permitted config directory'];
        }

        if (is_link($targetPath)) {
            $realTarget = realpath($targetPath);
            if ($realTarget === false || dirname($realTarget) !== $realBase) {
                return ['path_constraint', 'target is a symlink pointing outside the config directory'];
            }
        }

        if (file_exists($targetPath) && !is_file($targetPath)) {
            return ['path_constraint', 'target exists and is not a regular file'];
        }

        return null;
    }
}
