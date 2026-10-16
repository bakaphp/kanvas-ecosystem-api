<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Exceptions;

use RuntimeException;

/**
 * Typesense alters a collection's schema one request at a time and answers every concurrent alter
 * with a 422. The collection is being brought up to date by another worker, so the write that hit
 * this should wait and run again, not fail.
 */
final class CollectionUpdateInProgressException extends RuntimeException
{
    public function __construct(string $collection)
    {
        parent::__construct(sprintf('Typesense collection [%s] is being updated by another process; retry shortly.', $collection));
    }
}
