<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Exceptions;

use Exception;
use Kanvas\Workflow\Contracts\SilentWorkflowException;

class AgentReplySkippedException extends Exception implements SilentWorkflowException
{
}
