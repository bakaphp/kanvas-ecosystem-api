<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Rules\Models\Rule;
use Workflow\Models\StoredWorkflow as BaseStoredWorkflow;
use Workflow\Serializers\Serializer;

class StoredWorkflow extends BaseStoredWorkflow
{
    protected $connection = 'workflow';

    public function getActivityName(): string
    {
        return class_basename($this->logs()->first()?->class ?? '');
    }

    public function getUnSerializeArgument(): array
    {
        if (empty($this->arguments)) {
            return [];
        }

        try {
            $unserialized = Serializer::unserialize($this->arguments);

            return $this->filterUnserializedData($unserialized);
        } catch (Exception) {
        }

        return [];
    }

    public function getUnSerializeOutput(): array
    {
        if (empty($this->output)) {
            return [];
        }

        try {
            $unserialized = Serializer::unserialize($this->output);

            return array_filter(
                $unserialized,
                fn ($value) => ! $this->isIgnoredType($value)
            );
        } catch (Exception) {
        }

        return [];
    }

    protected function filterUnserializedData(array $data): array
    {
        $results = [];

        foreach ($data as $key => $value) {
            if ($this->isIgnoredType($value)) {
                continue;
            }

            if (is_array($value)) {
                $results['params'] = $value;
            } elseif (is_object($value) && method_exists($value, 'toArray')) {
                $results[get_class($value)] = $value->toArray();
            }
        }

        return $results;
    }

    protected function isIgnoredType(mixed $value): bool
    {
        return $value instanceof Apps
            || $value instanceof Companies
            || $value instanceof Rule
            || $value instanceof Users;
    }
}
