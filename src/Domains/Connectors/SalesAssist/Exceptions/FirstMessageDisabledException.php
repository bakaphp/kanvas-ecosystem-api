<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Exceptions;

use Kanvas\Workflow\Contracts\SilentWorkflowException;
use RuntimeException;

final class FirstMessageDisabledException extends RuntimeException implements SilentWorkflowException
{
}
