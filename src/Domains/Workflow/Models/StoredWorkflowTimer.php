<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Workflow\Models\StoredWorkflowTimer as ModelsStoredWorkflowTimer;

class StoredWorkflowTimer extends ModelsStoredWorkflowTimer
{
    protected $connection = 'workflow';
}
