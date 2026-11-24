<?php

declare(strict_types=1);

namespace Kanvas\Scribe\DocumentSequences\Exceptions;

use Kanvas\Exceptions\ValidationException;

/**
 * The sequence cannot hand out a number: no authorized range configured, the range is used up, or it
 * expired. Thrown before anything is consumed, so the caller's document stays a draft.
 */
class DocumentSequenceUnavailableException extends ValidationException
{
}
