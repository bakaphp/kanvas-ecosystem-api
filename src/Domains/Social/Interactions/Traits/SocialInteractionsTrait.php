<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\Traits;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Social\Interactions\Actions\CreateEntityInteractionAction;
use Kanvas\Social\Interactions\Actions\CreateInteraction;
use Kanvas\Social\Interactions\DataTransferObject\EntityInteraction;
use Kanvas\Social\Interactions\DataTransferObject\Interaction;
use Kanvas\Social\Interactions\DataTransferObject\LikeEntityInput;
use Kanvas\Social\Interactions\Models\EntityInteractions;
use Kanvas\Social\Interactions\Models\Interactions;
use Kanvas\Social\Interactions\Models\UsersInteractions;
use Kanvas\Social\Interactions\Repositories\EntityInteractionsRepository;
use Kanvas\Users\Enums\UserConfigEnum;
use Kanvas\Users\Models\Users;

trait SocialInteractionsTrait
{
    use LikableTrait;

    public function addInteraction(Model $entity, string $interaction, ?string $note = null): UsersInteractions|EntityInteractions
    {
        if ($this instanceof Users) {
            $interaction = (
                new CreateInteraction(
                    new Interaction(
                        $interaction,
                        $this->app,
                        $interaction
                    )
                ))->execute();

            return UsersInteractions::firstOrCreate([
                'users_id' => $this->getId(),
                'interactions_id' => $interaction->getId(),
                'entity_id' => $entity->getId(),
                'entity_namespace' => $entity::class,
                'is_deleted' => 0,
            ], [
                'notes' => $note,
            ]);
        }

        return (
            new CreateEntityInteractionAction(
                (new EntityInteraction(
                    $this,
                    $entity,
                    $interaction,
                    $note
                )),
                $this->app
            ))->execute();
    }

    /**
     * Given a visitorInput get the social interactions for the entity.
     *
     * @param array $visitorInput<string,string>
     *
     * @return array<array-key,bool> #graph Interactions
     */
    public function getEntitySocialInteractions(array $visitorInput): array
    {
        return EntityInteractionsRepository::getEntityInteractions(
            new LikeEntityInput(
                $visitorInput['id'],
                $visitorInput['type'],
                $this->uuid,
                static::class
            )
        );
    }

    public function getUserSocialInteractions(): array
    {
        //@todo i hate this, lets look for a better way to get the current user
        $user = auth()->user();
        $userInteractions = $user->get(UserConfigEnum::USER_INTERACTIONS->value) ?? [];

        return $userInteractions[$this->getCacheKey()] ?? [];
    }
}
