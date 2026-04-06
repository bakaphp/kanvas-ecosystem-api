<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Azul\Exceptions;

use Kanvas\Exceptions\ValidationException;
use Throwable;

class AzulException extends ValidationException
{
    protected array $errorBody;

    public function __construct(string|array $message = "", int $code = 0, ?Throwable $previous = null, array $errorBody = [])
    {
        $message = is_array($message) ? implode(', ', $message) : $message;
        parent::__construct($message, $code, $previous);
        $this->errorBody = $errorBody;
    }

    public function getErrorBody(): array
    {
        return $this->errorBody;
    }
}
