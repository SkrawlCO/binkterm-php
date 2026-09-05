<?php

declare(strict_types=1);

namespace BinktermPHP\Crossroads;

/**
 * Base for Galactic Bloodshed identity-broker failures. Messages are safe to
 * log -- they never contain a race/governor password.
 */
class GalacticBloodshedIdentityException extends \RuntimeException
{
}
