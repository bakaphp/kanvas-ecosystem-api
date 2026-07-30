<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Exceptions;

use RuntimeException;

/**
 * Thrown when a bank feed tries to post to a company whose GL an ERP already owns. The ERP imports its own
 * cash batches as journal entries, so posting here would double-count. Fail loudly rather than double-book.
 */
class ExternalGlOwnershipException extends RuntimeException
{
}
