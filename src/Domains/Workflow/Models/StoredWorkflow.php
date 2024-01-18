<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Workflow\Models\StoredWorkflow as ModelsStoredWorkflow;

class StoredWorkflow extends ModelsStoredWorkflow
{
    protected $connection = 'workflow';
}
