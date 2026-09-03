<?php

declare(strict_types=1);

namespace BinktermPHP\Crossroads;

/**
 * Chessmata's per-IP account-creation rate limit (5/hour/IP) blocked
 * provisioning. Treat as transient: retry on the caller's next launch. Do NOT
 * fall back to a shared or anonymous identity.
 */
final class ChessmataProvisioningRateLimited extends ChessmataIdentityException
{
}
