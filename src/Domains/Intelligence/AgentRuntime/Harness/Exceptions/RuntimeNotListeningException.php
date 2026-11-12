<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Exceptions;

/**
 * Nothing answered on the runtime's port: the server is down, as opposed to answering with an error.
 * Only this one can be repaired by starting the server again.
 */
class RuntimeNotListeningException extends HarnessTransportException
{
}
