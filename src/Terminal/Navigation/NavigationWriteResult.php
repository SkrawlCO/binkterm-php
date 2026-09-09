<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * The outcome of a {@see NavigationConfigWriter::write()} attempt.
 *
 * Three shapes:
 *   - invalid input   → written=false, valid=false, errors=[…]  (old file intact)
 *   - I/O failure     → written=false, valid=true,  ioError set  (old file intact)
 *   - success         → written=true,  valid=true,  bytes/canonicalJson set
 */
final class NavigationWriteResult
{
    /**
     * @param array<int,ValidationError> $errors
     */
    private function __construct(
        public readonly bool $written,
        public readonly bool $valid,
        public readonly array $errors,
        public readonly ?string $ioError,
        public readonly ?string $ioErrorMessage,
        public readonly int $bytes,
        public readonly string $path,
        public readonly ?string $canonicalJson,
    ) {
    }

    /**
     * @param array<int,ValidationError> $errors
     */
    public static function invalid(string $path, array $errors): self
    {
        return new self(false, false, array_values($errors), null, null, 0, $path, null);
    }

    public static function ioFailure(string $path, string $code, string $message): self
    {
        return new self(false, true, [], $code, $message, 0, $path, null);
    }

    public static function success(string $path, int $bytes, string $canonicalJson): self
    {
        return new self(true, true, [], null, null, $bytes, $path, $canonicalJson);
    }

    public function errorSummary(): string
    {
        if ($this->ioError !== null) {
            return "{$this->ioError}: {$this->ioErrorMessage}";
        }

        return implode('; ', array_map(static fn (ValidationError $e) => (string) $e, $this->errors));
    }

    /** @return array<string,mixed> structured payload for the admin-daemon response */
    public function toArray(): array
    {
        return [
            'written'   => $this->written,
            'valid'     => $this->valid,
            'path'      => $this->path,
            'bytes'     => $this->bytes,
            'errors'    => array_map(static fn (ValidationError $e) => [
                'code'    => $e->code,
                'message' => $e->message,
                'path'    => $e->path,
            ], $this->errors),
            'io_error'  => $this->ioError,
            'io_error_message' => $this->ioErrorMessage,
            'canonical' => $this->canonicalJson,
        ];
    }
}
