<?php

declare(strict_types=1);

namespace Baka\Http\Exceptions;

use RuntimeException;

/**
 * Thrown when a URL is rejected by the SSRF guard — a disallowed scheme, a malformed
 * URL, a host that resolves to a private/reserved address, or a response that exceeds
 * the configured byte cap.
 */
class SsrfException extends RuntimeException
{
}
