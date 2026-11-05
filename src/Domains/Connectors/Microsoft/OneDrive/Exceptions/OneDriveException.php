<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\OneDrive\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveErrorReasonEnum;
use Throwable;

class OneDriveException extends Exception implements ShouldntReport
{
    public function __construct(
        public readonly OneDriveErrorReasonEnum $reason,
        string $message,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, previous: $previous);
    }
}
