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
        if (isset($this->arguments)) {
            $unserialize = unserialize($this->arguments)->getClosure();
            return $unserialize();
        }
        return null;
    }

    public function getUnserializeOutput(): mixed
    {
        if (isset($this->arguments)) {
            $unserialize = unserialize($this->output)->getClosure();
            return $unserialize();
        }
        return null;
    }
}
