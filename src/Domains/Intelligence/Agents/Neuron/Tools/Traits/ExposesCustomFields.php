<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Guild\Models\BaseModel;

/**
 * Surfaces a CRM record's custom fields for the agent to narrate — scrubbed of internal plumbing:
 * id/uuid references (Apollo/external ids, FK-style keys) and non-scalar blobs (raw enrichment
 * payloads) that are noise to a human reader, never useful business info.
 */
trait ExposesCustomFields
{
    /**
     * @return array<string, scalar>
     */
    protected function relevantCustomFields(BaseModel $entity): array
    {
        $fields = [];

        foreach ($entity->getAll() as $name => $value) {
            $key = (string) $name;

            if ($value === null || $value === '' || ! is_scalar($value)) {
                continue;
            }

            if (str_starts_with($key, '_') || preg_match('/(?:^|_)(id|ids|uuid)$/i', $key) === 1) {
                continue;
            }

            $fields[$key] = $value;
        }

        return $fields;
    }
}
