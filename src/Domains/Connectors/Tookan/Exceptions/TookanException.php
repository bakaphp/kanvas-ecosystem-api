<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tookan\Exceptions;

use Kanvas\Exceptions\ValidationException;
use Throwable;

class TookanException extends ValidationException
{
    protected array $errorBody;

    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null, array $errorBody = [])
    {
        parent::__construct($message, $code, $previous);
        $this->errorBody = $errorBody;
    }

    public function getErrorBody(): array
    {
        return $this->errorBody;
    }
}
