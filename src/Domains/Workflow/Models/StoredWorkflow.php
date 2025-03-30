<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Rules\Models\Rule;
use Workflow\Models\StoredWorkflow as ModelsStoredWorkflow;
use Workflow\Serializers\Serializer;

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
                $unserialize = Serializer::unserialize($this->arguments);

                foreach ($unserialize as $key => $value) {
                    if ($value instanceof Apps || $value instanceof Companies || $value instanceof Rule || $value instanceof Users) {
                        unset($unserialize[$key]);
                    }
                }

                return $unserialize;
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
                $unserialize = Serializer::unserialize($this->output);
                foreach ($unserialize as $key => $value) {
                    if ($value instanceof Apps || $value instanceof Companies || $value instanceof Rule || $value instanceof Users) {
                        unset($unserialize[$key]);
                    }
                }

                return $unserialize;
            } catch (Exception $e) {
                return null;
            }
        }

        return null;
    }
}
