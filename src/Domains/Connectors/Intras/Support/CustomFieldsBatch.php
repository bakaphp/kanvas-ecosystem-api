<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Support;

use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\SystemModules\Models\SystemModules;

/**
 * Custom fields for a batch of rows in one query, keyed entity id => [name => value], where
 * `$model->get()` per row would be one Redis or SQL round trip each.
 *
 * Not filtered by `apps_custom_fields.companies_id`: that column records the writer's company context,
 * not the entity's owner, so the caller scopes the ids by tenant first and this reads them as given.
 * `Reporting/*Definition::customFieldsFor()` is the same query, kept there until those files are next touched.
 */
final class CustomFieldsBatch
{
    /**
     * @param class-string $modelClass
     * @param list<int> $entityIds
     * @param list<string>|null $names only these fields, or every field when null
     *
     * @return array<int, array<string, string>>
     */
    public static function forEntities(string $modelClass, array $entityIds, ?array $names = null): array
    {
        if ($entityIds === []) {
            return [];
        }

        $rows = AppsCustomFields::query()
            ->whereIn('model_name', array_unique([$modelClass, SystemModules::getLegacyNamespace($modelClass)]))
            ->whereIn('entity_id', $entityIds)
            ->when($names !== null, fn ($query) => $query->whereIn('name', $names))
            ->where('is_deleted', 0)
            ->get(['entity_id', 'name', 'value']);

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->entity_id][(string) $row->name] = (string) $row->value;
        }

        return $map;
    }
}
