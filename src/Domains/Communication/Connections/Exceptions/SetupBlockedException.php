<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Exceptions;

use RuntimeException;

/**
 * Setup cannot finish until something outside Kanvas changes: a registration someone else owns, a
 * registration still in review. Not a fault — the connection is reported blocked with the reason,
 * and the sweep tries again.
 */
final class SetupBlockedException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $detail = ''
    ) {
        parent::__construct($detail !== '' ? $detail : $reason);
    }
}
