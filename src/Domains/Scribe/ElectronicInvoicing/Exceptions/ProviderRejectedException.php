<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Exceptions;

use Kanvas\Exceptions\ValidationException;

/**
 * The provider refused the document before it reached the authority: nothing was consumed, so the same fiscal
 * row is fixed and sent again rather than burned.
 */
class ProviderRejectedException extends ValidationException
{
}
