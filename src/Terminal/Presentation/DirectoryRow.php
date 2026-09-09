<?php

namespace BinktermPHP\Terminal\Presentation;

/**
 * One selectable destination in a {@see Directory}.
 *
 * Carries presentation data only. `value` is an opaque payload the caller
 * attaches so it can dispatch on the selection without tracking flat indices —
 * {@see \BinktermPHP\TelnetServer\TerminalShellInterface::showDirectory()}
 * returns it verbatim.
 */
final class DirectoryRow
{
    /**
     * @param string      $label       primary destination name
     * @param string|null $description  one-line secondary context, rendered under the label
     * @param string|null $badge        short right-of-label tag (e.g. "Multiplayer", "Gateway")
     * @param mixed       $value        opaque caller payload returned on selection
     */
    public function __construct(
        public readonly string $label,
        public readonly ?string $description = null,
        public readonly ?string $badge = null,
        public readonly mixed $value = null,
    ) {
    }
}
