<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Exceptions;

use Kanvas\Exceptions\ValidationException;

/**
 * Fiscal rows are tax records: never deleted, and frozen once they may have reached the authority.
 */
class ImmutableFiscalRecordException extends ValidationException
{
}
