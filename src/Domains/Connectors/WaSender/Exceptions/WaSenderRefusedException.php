<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Exceptions;

use Kanvas\Exceptions\ValidationException;

/**
 * A 4xx from WaSender: the API understood the request and declined it — media over its 25MB decrypt
 * limit, an expired media key, an unsupported type. None of it changes on a retry, so a caller can
 * skip quietly rather than report. Extends ValidationException so existing catches keep working.
 */
class WaSenderRefusedException extends ValidationException
{
}
