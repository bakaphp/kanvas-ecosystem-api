<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Exceptions;

use Kanvas\Exceptions\ValidationException;

/**
 * Thrown when an agent runtime is unreachable through no fault of our code: the machine's sshd is
 * down/blocked, SSH auth fails, or the target container has been removed. These are expected states
 * for a dead or reaped deployment — callers treat them as a health signal (flag the deployment) and
 * MUST NOT report them to Sentry on every cron tick, which is what buried the tracker in noise
 * (KANVAS-ECOSYSTEM-5RP / 5MV / 5BH).
 */
class AgentRuntimeUnreachableException extends ValidationException
{
}
