<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Exceptions;

use Kanvas\Exceptions\ValidationException;

class InvalidQuoteSowException extends ValidationException
{
    /**
     * @param list<string> $problems
     */
    public function __construct(
        public readonly array $problems,
    ) {
        parent::__construct('The SOW is not consistent with the quote: ' . implode(' ', $problems));
    }
}
