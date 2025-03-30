<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Exception;
use Workflow\Models\StoredWorkflow as ModelsStoredWorkflow;

class StoredWorkflow extends ModelsStoredWorkflow
{
    protected $connection = 'workflow';

    public function getActivityName(): string
    {
        return class_basename($this->logs()->first()->class);
    }

    public function getUnSerializeArgument(): mixed
    {
        if (isset($this->arguments) && ! empty($this->arguments)) {
            try {
                $unserialize = unserialize($this->arguments)->getClosure();

                return $unserialize();
            } catch (Exception $e) {
                return null;
            }
        }

        return null;
    }

    public function getUnSerializeOutput(): mixed
    {
        if (isset($this->output) && ! empty($this->output)) {
            try {
                $unserialize = unserialize($this->output)->getClosure();

                return $unserialize();
            } catch (Exception $e) {
                return null;
            }
        }

        return null;
    }
}
