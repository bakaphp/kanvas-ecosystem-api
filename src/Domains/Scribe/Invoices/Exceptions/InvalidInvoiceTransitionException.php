<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Exceptions;

use Kanvas\Scribe\Ledger\Exceptions\InvalidDocumentTransitionException;

class InvalidInvoiceTransitionException extends InvalidDocumentTransitionException
{
}
