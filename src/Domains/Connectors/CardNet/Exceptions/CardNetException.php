<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet\Exceptions;

use RuntimeException;
use Throwable;

class CardNetException extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly array $responseBody = [],
    ) {
        parent::__construct($message, $code, $previous);
    }
}
