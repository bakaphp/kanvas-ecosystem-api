<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Workflow\Models\StoredWorkflow as ModelsStoredWorkflow;

class StoredWorkflow extends ModelsStoredWorkflow
{
    protected $connection = 'workflow';

    public function getActivityName(): string
    {
        return class_basename($this->logs()->first()->class);
    }

    public function getUnserializeArgument(): mixed
    {
        $unserialize = unserialize($this->arguments)->getClosure();
        return $unserialize();
    }

    public function getUnserializeOutput(): mixed
    {
        $unserialize = unserialize($this->output)->getClosure();
        return $unserialize();
    }
}
