<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Classification\Services;

use Kanvas\Guild\Customers\Classification\DataTransferObject\PeopleTaxonomy;
use Kanvas\Guild\Customers\Classification\Enums\PeopleClassificationFieldEnum as FieldEnum;
use Kanvas\Guild\Customers\Models\People;

/**
 * Touches only the taxonomy's own tags: syncTags() would also wipe the person's other tags, such as
 * the groups and interests a connector imported.
 */
class PeopleClassificationTagsService
{
    public static function reconcile(People $people, PeopleTaxonomy $taxonomy): void
    {
        $wanted = $taxonomy->tagsFor($people->get(FieldEnum::LEVEL->value), $people->get(FieldEnum::AREA->value));

        $held = $people->tags()->whereIn('name', $taxonomy->allTags())->pluck('name')->all();
        $stale = array_values(array_diff($held, $wanted));
        $missing = array_values(array_diff($wanted, $held));

        if ($stale !== []) {
            $people->removeTags($stale);
        }

        if ($missing === []) {
            return;
        }

        $actor = $people->user ?? $people->company?->getAiAgentUser();

        if ($actor !== null) {
            $people->addTags(
                $missing,
                $people->app,
                $actor,
                $people->company,
            );
        }
    }
}
