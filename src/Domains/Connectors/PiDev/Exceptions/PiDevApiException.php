<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PiDev\Exceptions;

use Kanvas\Exceptions\ValidationException;

/**
 * A pi.dev HTTP error that carries its status code as a first-class property. Kanvas'
 * ValidationException stores its second constructor arg as `reason`, not the exception code, so
 * callers that branch on the status (cancel-on-409, poll-on-404) read `$e->status` instead of
 * getCode(). Extends ValidationException so it stays client-safe and existing catches still match.
 */
class PiDevApiException extends ValidationException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message, $status);
    }
}
