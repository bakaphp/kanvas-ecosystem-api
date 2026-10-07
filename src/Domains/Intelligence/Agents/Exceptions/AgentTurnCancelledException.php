<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Exceptions;

use RuntimeException;

/**
 * The person asked for the running turn to stop. Not a fault: nothing is reported, no fallback is
 * written, and the caller tells the chat the turn was cancelled rather than failed.
 */
class AgentTurnCancelledException extends RuntimeException
{
    public function __construct(public readonly string $threadId)
    {
        parent::__construct("Agent turn on thread {$threadId} was cancelled on request.");
    }
}
