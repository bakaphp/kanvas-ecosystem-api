<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;

/**
 * Bulk [brushcrazy_id => kanvas_id] lookups over `apps_custom_fields`.
 *
 * Two rules this class exists to enforce:
 *
 * 1. **Resolution is by external id only, never by slug or name.** A Kanvas-native row carries no
 *    BRUSHCRAZY_* custom field, so it is structurally unreachable by the mirror — which is what
 *    keeps a stale sync run from clobbering data created directly in Kanvas after cutover.
 *
 * 2. **Soft-deleted targets stay in the map.** BrushCrazy treats a refund revert as a *restore*
 *    (`RestoreRegistrations`), not a re-create. If a deleted row fell out of the map, the sync
 *    would read "not found" as "create" and duplicate every reverted seat.
 */
class IdMapRepository
{
    /**
     * One query per (model, field). Index-backed by `companies_id_model_name_entity_id_name`.
     *
     * @return array<string, int> external id => kanvas id
     */
    public function load(int $companyId, string $modelClass, CustomFieldEnum $field): array
    {
        return $this->forwardMap($this->query($companyId, $modelClass, $field));
    }

    /**
     * Narrow variant for a single chunk. Preferred over load() for the high-cardinality maps
     * (People at ~96k rows) where a resident array costs more than one query per chunk.
     *
     * @param  array<int, int|string>  $externalIds
     * @return array<string, int>
     */
    public function loadFor(
        int $companyId,
        string $modelClass,
        CustomFieldEnum $field,
        array $externalIds
    ): array {
        if ($externalIds === []) {
            return [];
        }

        return $this->forwardMap(
            $this->query($companyId, $modelClass, $field)->whereIn('value', array_map('strval', $externalIds))
        );
    }

    /**
     * Reverse direction: which BrushCrazy ids does Kanvas already know about for these entities.
     * Used to spot Kanvas rows whose source row is gone (hard deletes).
     *
     * @param  array<int, int>  $entityIds
     * @return array<int, string> kanvas id => external id
     */
    public function loadReverse(
        int $companyId,
        string $modelClass,
        CustomFieldEnum $field,
        array $entityIds
    ): array {
        if ($entityIds === []) {
            return [];
        }

        return $this->query($companyId, $modelClass, $field)
            ->whereIn('entity_id', $entityIds)
            ->pluck('value', 'entity_id')
            ->mapWithKeys(fn ($value, $entityId) => [(int) $entityId => (string) $value])
            ->all();
    }

    private function query(int $companyId, string $modelClass, CustomFieldEnum $field): Builder
    {
        return DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('companies_id', $companyId)
            ->where('model_name', $modelClass)
            ->where('name', $field->value)
            ->where('is_deleted', 0);
    }

    /**
     * @return array<string, int>
     */
    private function forwardMap(Builder $query): array
    {
        return $query->pluck('entity_id', 'value')
            ->mapWithKeys(fn ($entityId, $value) => [(string) $value => (int) $entityId])
            ->all();
    }
}
