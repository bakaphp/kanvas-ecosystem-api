<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Exceptions;

use Kanvas\Exceptions\ValidationException;

/**
 * Carries the HTTP status as a property because Kanvas' ValidationException stores its second
 * constructor argument as `reason`, not the exception code — `getCode()` is always 0. Callers that
 * branch on status (429 backoff, 409 version conflict on an agent update, 404 on a session the
 * platform no longer knows) must read `->status`.
 */
class ClaudeAgentApiException extends ValidationException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message, $status);
    }
}
