<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Exceptions;

use Kanvas\Intelligence\Agents\Exceptions\AgentReplySkippedException;

/**
 * An inbound message no agent may answer, for a reason this domain decides: the connection was
 * switched off, nobody owns it, it was handed to another agent. An expected outcome in every lane a
 * skipped reply already is; `reason` is the short code the message is tagged with.
 */
final class InboundNotAnsweredException extends AgentReplySkippedException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
