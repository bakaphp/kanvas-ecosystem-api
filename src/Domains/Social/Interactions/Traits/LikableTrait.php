<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Social\Enums\InteractionEnum;
use Kanvas\Social\Interactions\Models\EntityInteractions;
use Kanvas\Social\Interactions\Models\Interactions;
use Kanvas\Social\Interactions\Models\UsersInteractions;
use Kanvas\Users\Models\Users;

trait LikableTrait
{
    /**
     * Like an entity.
     * @param ?string $note
     */
    public function like(Model $entity, ?string $note = null, bool $isDislike = false): UsersInteractions|EntityInteractions
    {
        $interaction = Interactions::fromApp()->where('name', InteractionEnum::getLikeInteractionEnumValue($isDislike))->firstOrFail();

        if ($this instanceof Users) {
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

        return EntityInteractions::firstOrCreate([
            'entity_id' => $this->getId(),
            'entity_namespace' => $this::class,
            'interactions_id' => $interaction->getId(),
            'interacted_entity_id' => $entity->getId(),
            'interacted_entity_namespace' => $entity::class,
            'is_deleted' => 0,
        ], [
            'notes' => $note,
        ]);
    }

    /**
     * Dislike an entity.
     * @param ?string $note
     * @param bool $isDislike
     */
    public function dislike(Model $entity, ?string $note = null): UsersInteractions|EntityInteractions
    {
        return $this->like($entity, $note, true);
    }

    /**
     * Unlike an entity.
     * @param ?string $note
     */
    public function unLike(Model $entity, ?string $note = null, bool $isDislike = false): bool
    {
        $interaction = Interactions::fromApp()->where('name', InteractionEnum::getLikeInteractionEnumValue($isDislike))->firstOrFail();

        if ($this instanceof Users) {
            $entityInteraction = UsersInteractions::where('users_id', $this->getId())
                ->where('interactions_id', $interaction->getId())
                ->where('entity_id', $entity->getId())
                ->where('entity_namespace', $entity::class)
                ->first();
        } else {
            $entityInteraction = EntityInteractions::where('entity_id', $this->getId())
                ->where('entity_namespace', $this::class)
                ->where('interactions_id', $interaction->getId())
                ->where('interacted_entity_id', $entity->getId())
                ->where('interacted_entity_namespace', $entity::class)
                ->first();
        }

        return $entityInteraction ? $entityInteraction->softDelete() : false;
    }

    /**
     * Unlike a dislike of an entity.
     * @param ?string $note
     * @param bool $isDislike
     */
    public function unLikeDislike(Model $entity, ?string $note = null): bool
    {
        return $this->unLike($entity, $note, true);
    }

    /**
     * Check if an entity has a like.
     */
    public function hasLiked(Model $entity, bool $isDislike = false): bool
    {
        $interaction = Interactions::fromApp()->where('name', InteractionEnum::getLikeInteractionEnumValue($isDislike))->firstOrFail();

        if ($this instanceof Users) {
            return UsersInteractions::where('users_id', $this->getId())
                ->where('interactions_id', $interaction->getId())
                ->where('entity_id', $entity->getId())
                ->where('entity_namespace', $entity::class)
                ->count() > 0;
        }

        return EntityInteractions::where('entity_id', $this->getId())
            ->where('entity_namespace', $this::class)
            ->where('interactions_id', $interaction->getId())
            ->where('interacted_entity_id', $entity->getId())
            ->where('interacted_entity_namespace', $entity::class)
            ->count() > 0;
    }

    /**
     * Check if an entity has a dislike.
     */
    public function hasDisliked(Model $entity): bool
    {
        return $this->hasDisliked($entity, true);
    }

    /**
     * Retrieve likes of entity.
     */
    public function likes(bool $isDislike = false): HasMany
    {
        $interaction = Interactions::fromApp()->where('name', InteractionEnum::getLikeInteractionEnumValue($isDislike))->firstOrFail();

        if ($this instanceof Users) {
            return $this->hasMany(UsersInteractions::class, 'users_id', 'id')
                ->where('interactions_id', $interaction->getId());
        }

        return $this->hasMany(EntityInteractions::class, 'entity_id', 'id')
            ->where('entity_namespace', $this::class)
            ->where('interactions_id', $interaction->getId());
    }

    /**
    * Retrieve dislikes of entity.
    */
    public function dislikes(): HasMany
    {
        return $this->likes(true);
    }
}
