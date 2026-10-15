<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Browser;

use RuntimeException;
use Throwable;

class BrowserToolException extends RuntimeException
{
    public function __construct(
        public readonly BrowserErrorCode $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @return array{success: false, error: string, message: string} */
    public function toToolResponse(): array
    {
        return [
            'success' => false,
            'error' => $this->errorCode->value,
            'message' => $this->getMessage(),
        ];
    }
}
