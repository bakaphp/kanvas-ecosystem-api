<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\Repositories;

use Kanvas\Social\Interactions\DataTransferObject\LikeEntityInput;
use Kanvas\Social\Interactions\Models\EntityInteractions;
use Kanvas\Social\Interactions\Models\Interactions;

class EntityInteractionsRepository
{
    /**
     * Given a visitorInput get the social interactions for the entity.
     *
     * @param array $visitorInput<string,string>
     *
     * @return array<array-key,bool> #graph Interactions
     */
    public static function getEntityInteractions(LikeEntityInput $entityInput): array
    {
        $interactions = [];

        /**
         * @var \Illuminate\Database\Eloquent\Collection <EntityInteractions>
         */
        $visitorInteractions = EntityInteractions::select(Interactions::getFullTableName() . '.name')
            ->join(
                Interactions::getFullTableName(),
                Interactions::getFullTableName() . '.id',
                '=',
                EntityInteractions::getFullTableName() . '.interactions_id'
            )
            ->where(
                EntityInteractions::getFullTableName() . '.interacted_entity_id',
                $entityInput->interacted_entity_id
            )
            ->where(
                EntityInteractions::getFullTableName() . '.interacted_entity_namespace',
                $entityInput->interacted_entity_namespace
            )
            ->where(EntityInteractions::getFullTableName() . '.entity_id', $entityInput->entity_id)
            ->where(EntityInteractions::getFullTableName() . '.entity_namespace', $entityInput->entity_namespace)
            ->groupBy(Interactions::getFullTableName() . '.name')
            ->get();

        foreach ($visitorInteractions as $interaction) {
            $interactions[$interaction->name] = true;
        }

        return $interactions;
    }
}
