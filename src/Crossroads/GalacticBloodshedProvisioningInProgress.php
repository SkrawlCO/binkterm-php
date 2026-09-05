<?php

declare(strict_types=1);

namespace BinktermPHP\Crossroads;

/**
 * Another launch already claimed this user's provisioning attempt and it
 * hasn't gone stale yet (see GalacticBloodshedIdentity::STALE_ATTEMPT_SEC).
 * Thrown instead of starting a second concurrent `enrol` run, which would
 * otherwise risk creating two GB races for one BinkTerm user. Treat as
 * transient: tell the caller to retry shortly.
 */
final class GalacticBloodshedProvisioningInProgress extends GalacticBloodshedIdentityException
{
}
