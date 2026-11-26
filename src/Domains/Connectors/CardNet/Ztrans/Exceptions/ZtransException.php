<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet\Ztrans\Exceptions;

use RuntimeException;

class ZtransException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $responseCode = '',
        public readonly string $internalResponseCode = '',
        public readonly array $body = [],
    ) {
        parent::__construct($message);
    }
}
