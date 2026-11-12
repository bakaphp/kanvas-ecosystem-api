<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Exceptions;

/**
 * The factory refused to open a checkout another session holds. `$holder` is the lock's owner: the uuid
 * of the AgentTaskSession that opened it, when Kanvas opened it at all.
 */
class CheckoutInUseException extends HarnessTransportException
{
    public function __construct(
        string $message,
        public readonly string $holder,
    ) {
        parent::__construct($message);
    }
}
