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
    public function like(Model $entity, ?string $note = null): UsersInteractions|EntityInteractions
    {
        $interaction = Interactions::fromApp()->where('name', InteractionEnum::LIKE->getValue())->firstOrFail();
        
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

    public function unLike(Model $entity, ?string $note = null): bool
    {
        $interaction = Interactions::fromApp()->where('name', InteractionEnum::LIKE->getValue())->firstOrFail();

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

    public function hasLiked(Model $entity): bool
    {
        $interaction = Interactions::fromApp()->where('name', InteractionEnum::LIKE->getValue())->firstOrFail();

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

    public function likes(): HasMany
    {
        $interaction = Interactions::fromApp()->where('name', InteractionEnum::LIKE->getValue())->firstOrFail();

        if ($this instanceof Users) {
            return $this->hasMany(UsersInteractions::class, 'users_id', 'id')
                ->where('interactions_id', $interaction->getId());
        }

        return $this->hasMany(EntityInteractions::class, 'entity_id', 'id')
            ->where('entity_namespace', $this::class)
            ->where('interactions_id', $interaction->getId());
    }
}
