<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AgentRuntime\Exceptions;

use RuntimeException;

/**
 * Thrown internally when the OpenClaw gateway returns HTTP 401.
 *
 * Used as a control-flow signal for the chat action to refresh its cached
 * gateway token and retry the request once. Not meant to surface to users.
 */
class UnauthorizedGatewayException extends RuntimeException
{
}
