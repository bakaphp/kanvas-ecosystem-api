<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Jira\Exceptions;

use Kanvas\Exceptions\ValidationException;

class JiraException extends ValidationException
{
    public function __construct(string $message = '', int $code = 0)
    {
        parent::__construct($message, $code);
    }
}
