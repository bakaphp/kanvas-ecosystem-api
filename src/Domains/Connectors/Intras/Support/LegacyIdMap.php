<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Support;

use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;

/**
 * SIPGO id => Kanvas id for one model and company, read straight from apps_custom_fields in one
 * query. Importers resolve by this before falling back to a name match, so a record renamed in
 * Kanvas is updated rather than re-created under its old SIPGO name.
 */
class LegacyIdMap
{
    /**
     * @param class-string $modelClass
     * @param list<int>|null $onlyLegacyIds just these SIPGO ids, or the whole company when null
     *
     * @return array<int, int>
     */
    public static function load(
        string $modelClass,
        CustomFieldEnum $field,
        Companies $company,
        ?array $onlyLegacyIds = null
    ): array {
        return DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('companies_id', $company->getId())
            ->where('model_name', $modelClass)
            ->where('name', $field->value)
            ->when($onlyLegacyIds !== null, fn ($query) => $query->whereIn('value', array_map('strval', $onlyLegacyIds)))
            ->where('is_deleted', 0)
            ->pluck('entity_id', 'value')
            ->mapWithKeys(fn ($kanvasId, $legacyId) => [(int) $legacyId => (int) $kanvasId])
            ->all();
    }
}
