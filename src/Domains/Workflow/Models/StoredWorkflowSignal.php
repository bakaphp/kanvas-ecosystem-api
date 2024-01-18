<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Workflow\Models\StoredWorkflowSignal as ModelsStoredWorkflowSignal;

class StoredWorkflowSignal extends ModelsStoredWorkflowSignal
{
    protected $connection = 'workflow';
}
