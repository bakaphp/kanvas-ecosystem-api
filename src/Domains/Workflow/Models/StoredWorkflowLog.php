<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Workflow\Models\StoredWorkflowLog as ModelsStoredWorkflowLog;

class StoredWorkflowLog extends ModelsStoredWorkflowLog
{
    protected $connection = 'workflow';
}
