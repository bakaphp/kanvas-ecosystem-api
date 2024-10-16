<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Models;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Social\Follows\Observers\UserFollowObserver;
use Kanvas\Social\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 *  class UsersFollows
 *  @property int $id
 *  @property int $users_id
 *  @property int $entity_id
 *  @property int $companies_id
 *  @property int $companies_branches_id
 *  @property string $entity_namespace
 */
#[ObservedBy([UserFollowObserver::class])]
class UsersFollows extends BaseModel
{
    protected $guarded = [];
    protected $table = 'users_follows';

    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id', 'id');
    }

    /**
     * Convert the model instance to an array.
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        $array['entity'] = $this->entity;

        return $array;
    }

    /**
     * Get the entity associated with this follow.
     */
    public function getEntityAttribute(): mixed
    {
        return $this->entity_namespace::find($this->entity_id);
    }

    /**
     * Update the social count when a follow action occurs.
     */
    public function updateSocialCount(): void
    {
        $this->incrementSocialCount($this->user, 'followers');

        if ($this->entity_namespace === Users::class) {
            $following = $this->getEntityAttribute();
            $this->incrementSocialCount($following, 'following');
        }
    }

    /**
     * Decrease the social count when an unfollow action occurs.
     */
    public function decreaseSocialCount(): void
    {
        $this->decrementSocialCount($this->user, 'followers');

        if ($this->entity_namespace === Users::class) {
            $following = $this->getEntityAttribute();
            $this->decrementSocialCount($following, 'following');
        }
    }

    /**
     * Increment the social count for a given user.
     */
    private function incrementSocialCount(UserInterface $user, string $type): void
    {
        $this->updateSocialCountValue($user, $type, 1);
    }

    /**
     * Decrement the social count for a given user.
     */
    private function decrementSocialCount(UserInterface $user, string $type): void
    {
        $this->updateSocialCountValue($user, $type, -1);
    }

    /**
     * Update the social count value.
     */
    private function updateSocialCountValue(UserInterface $user, string $type, int $value): void
    {
        $key = "app_{$this->apps_id}_social_count";
        $socialCount = $user->get($key);

        $className = strtolower(class_basename($this->entity_namespace));
        $indexName = "{$className}_{$type}_count";

        $socialCount[$indexName] = max(0, ($socialCount[$indexName] ?? 0) + $value);

        $user->set($key, $socialCount);
    }
}
