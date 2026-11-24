<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Exceptions;

use Kanvas\Scribe\Banking\Enums\BankStatementRejectionEnum;
use RuntimeException;

class BankStatementRejectedException extends RuntimeException
{
    public function __construct(
        public readonly BankStatementRejectionEnum $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
