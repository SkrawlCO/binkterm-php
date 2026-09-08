<?php

declare(strict_types=1);

namespace BinktermPHP\Qwk;

/**
 * Raised when a QWK inbound import cannot proceed for a reason that is the
 * packet's or configuration's fault rather than a programming error: an unknown
 * mailbox, or a packet whose CONTROL.DAT identity does not match the mailbox it
 * is being imported into. Parser-level problems surface as the parser's own
 * RuntimeException.
 */
final class QwkImportException extends \RuntimeException
{
}
