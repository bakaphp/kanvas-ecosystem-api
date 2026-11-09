<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Exceptions;

use Kanvas\Exceptions\ValidationException;

/**
 * The provider holds this number for a document we did not send (issued outside Kanvas, or before a range was
 * reloaded). Waiting cannot settle it: the row is flagged for a person instead of polled again.
 */
class FiscalNumberHeldElsewhereException extends ValidationException
{
}
