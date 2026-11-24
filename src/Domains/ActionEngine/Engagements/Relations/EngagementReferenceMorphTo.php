<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Relations;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Kanvas\ActionEngine\Engagements\Support\EngagementReferenceMap;
use Override;

/**
 * A MorphTo that resolves `entity_type` through EngagementReferenceMap instead of the global morph
 * map, and reads a legacy row (no entity_type) as a reference to its lead, so old engagements need
 * no backfill. Eager loading groups those legacy rows under the lead alias by `leads_id`.
 */
class EngagementReferenceMorphTo extends MorphTo
{
    #[Override]
    protected function buildDictionary(EloquentCollection $models)
    {
        foreach ($models as $model) {
            [$type, $id] = self::referenceOf($model);

            if ($type === null || $id === null) {
                continue;
            }

            $this->dictionary[$type][$this->getDictionaryKey($id)][] = $model;
        }
    }

    #[Override]
    public function createModelByType($type)
    {
        $class = EngagementReferenceMap::classFor((string) $type);

        return new $class();
    }

    /**
     * @return array{0: string|null, 1: int|string|null}
     */
    public static function referenceOf(Model $engagement): array
    {
        $type = $engagement->getAttribute('entity_type');

        if ($type !== null && $type !== '') {
            return [$type, $engagement->getAttribute('entity_id')];
        }

        $leadId = $engagement->getAttribute('leads_id');

        return $leadId ? [EngagementReferenceMap::LEAD, $leadId] : [null, null];
    }
}
