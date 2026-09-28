<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Exceptions;

use Kanvas\Exceptions\ValidationException;

class ClaudeAgentApiException extends ValidationException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message, $status);
    }
}
