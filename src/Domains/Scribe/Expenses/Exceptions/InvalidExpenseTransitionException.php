<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Exceptions;

use Kanvas\Scribe\Ledger\Exceptions\InvalidDocumentTransitionException;

class InvalidExpenseTransitionException extends InvalidDocumentTransitionException
{
}
