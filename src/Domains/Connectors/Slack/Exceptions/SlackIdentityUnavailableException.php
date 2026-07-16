<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Exceptions;

use RuntimeException;

/**
 * Thrown when Slack can't be reached to resolve a sender's identity (rate limit, timeout, network
 * error). A control-flow signal that the failure is transient — the caller must not treat it as
 * "this person has no Kanvas account" and post the misleading "ask an admin to invite you" message.
 */
class SlackIdentityUnavailableException extends RuntimeException
{
}
