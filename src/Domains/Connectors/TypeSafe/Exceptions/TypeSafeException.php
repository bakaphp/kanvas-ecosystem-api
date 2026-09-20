<?php

declare(strict_types=1);

namespace Kanvas\Connectors\TypeSafe\Exceptions;

use Kanvas\Exceptions\ValidationException;

/**
 * Every failure out of this connector — unconfigured app, malformed question, HTTP error, unparseable
 * answer. One class on purpose: a call site's contract is "System One did not answer, use the LLM",
 * and it should not have to enumerate failure modes to honour it.
 */
class TypeSafeException extends ValidationException
{
}
