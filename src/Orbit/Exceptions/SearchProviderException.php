<?php

declare(strict_types=1);

namespace Aivo\Orbit\Exceptions;

use RuntimeException;

/**
 * Thrown by any SearchProviderInterface implementation when a search fails.
 *
 * Common cases: auth failure, rate limit, network timeout, malformed response.
 * The orchestrator catches this so a single failed adapter doesn't kill
 * the whole gap-search run — it just logs the failure into
 * orbit_search_runs.platforms_skipped and moves on.
 */
class SearchProviderException extends RuntimeException
{
}
