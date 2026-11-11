<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WebPos\Exceptions;

use RuntimeException;

/**
 * Thrown instead of Laravel's RequestException/ConnectionException, which carry the request URL, and the API key
 * is part of every WebPOS URL. Never chain the original as `previous`: Sentry reports the whole chain.
 */
class WebPosRequestException extends RuntimeException
{
    private const string NO_PERMISSION_CODE = '"E000';

    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $body = '',
    ) {
        parent::__construct($message, $status);
    }

    /**
     * E000 is answered before the body is read (an empty body gets it too): the license or key lacks the grant,
     * so nothing was processed.
     */
    public function isPermissionDenied(): bool
    {
        return str_contains($this->body, self::NO_PERMISSION_CODE) || str_contains($this->body, 'ApiKey Not Allowed');
    }
}
