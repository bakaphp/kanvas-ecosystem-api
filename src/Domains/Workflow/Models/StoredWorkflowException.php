<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Workflow\Models\StoredWorkflowException as ModelsStoredWorkflowException;

class StoredWorkflowException extends ModelsStoredWorkflowException
{
    protected $connection = 'workflow';
}
