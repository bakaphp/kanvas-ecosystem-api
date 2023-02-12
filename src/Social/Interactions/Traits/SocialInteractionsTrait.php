<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\Traits;

use Kanvas\Social\Interactions\Models\EntityInteractions;
use Kanvas\Social\Interactions\Models\Interactions;

trait SocialInteractionsTrait
{
    /**
     * Given a visitorInput get the social interactions for the entity.
     *
     * @param array $visitorInput<string,string>
     *
     * @return array<array-key,bool> #graph Interactions
     */
    public function getEntitySocialInteractions(array $visitorInput): array
    {
        $interactions = [];

        /**
         * @var \Illuminate\Database\Eloquent\Collection <EntityInteractions>
         */
        $visitorInteractions = EntityInteractions::select(Interactions::getFullTableName() . '.name')
            ->join(Interactions::getFullTableName(), Interactions::getFullTableName() . '.id', '=', EntityInteractions::getFullTableName() . '.interactions_id')
            ->where(EntityInteractions::getFullTableName() . '.interacted_entity_id', $this->uuid)
            ->where(EntityInteractions::getFullTableName() . '.interacted_entity_namespace', static::class)
            ->where(EntityInteractions::getFullTableName() . '.entity_id', (string) $visitorInput['id'])
            ->where(EntityInteractions::getFullTableName() . '.entity_namespace', (string) $visitorInput['type'])
            ->groupBy(Interactions::getFullTableName() . '.name')
            ->get();

        foreach ($visitorInteractions as $interaction) {
            $interactions[$interaction->name] = true;
        }

        return $interactions;
    }
}
