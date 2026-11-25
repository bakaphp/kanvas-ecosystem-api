<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Exceptions;

use NeuronAI\Exceptions\ToolRunsExceededException;

/**
 * The model kept calling a tool the agent does not hold. Its own type, so the report names the tool
 * that is missing instead of whichever real tool shared the loop with it.
 */
class UnknownToolRunsExceededException extends ToolRunsExceededException
{
}
