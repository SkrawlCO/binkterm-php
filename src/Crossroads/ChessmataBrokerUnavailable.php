<?php

declare(strict_types=1);

namespace BinktermPHP\Crossroads;

/**
 * The identity broker is not usable on this host (no encryption key configured,
 * or the self-hosted Chessmata service is unreachable). Callers should degrade
 * cleanly rather than treat this as a hard error.
 */
final class ChessmataBrokerUnavailable extends ChessmataIdentityException
{
}
