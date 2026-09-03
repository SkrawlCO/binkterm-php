<?php

declare(strict_types=1);

namespace BinktermPHP\Crossroads;

/**
 * Base for Chessmata identity-broker failures. Messages are safe to log — they
 * never contain a password, token or API key.
 */
class ChessmataIdentityException extends \RuntimeException
{
}
