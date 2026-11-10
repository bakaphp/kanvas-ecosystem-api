<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\CustomFields;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;

/** Read-time projections only: never write the effective mode back to custom-field storage. */
class CustomFieldValueResolver
{
    public function resolve(Model $entity, string $name): mixed
    {
        if ($entity instanceof Lead && $name === ConfigurationEnum::AI_MODE->value) {
            return $entity->resolveAiMode()?->value;
        }

        return $entity->get($name);
    }

    public function resolveAll(Model $entity, array $fields): array
    {
        if ($entity instanceof Lead) {
            $name = ConfigurationEnum::AI_MODE->value;
            $value = $this->resolve($entity, $name);

            if ($value !== null || array_key_exists($name, $fields)) {
                $fields[$name] = $value;
            }
        }

        return $fields;
    }
}
