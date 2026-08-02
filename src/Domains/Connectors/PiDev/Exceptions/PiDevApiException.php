<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PiDev\Exceptions;

use Kanvas\Exceptions\ValidationException;

class PiDevApiException extends ValidationException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message, $status);
    }
}
