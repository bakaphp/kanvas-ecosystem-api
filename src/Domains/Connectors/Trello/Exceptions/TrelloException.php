<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Trello\Exceptions;

use Kanvas\Exceptions\ValidationException;

class TrelloException extends ValidationException
{
    public function __construct(string $message = '', int $code = 0)
    {
        parent::__construct($message, $code);
    }
}
