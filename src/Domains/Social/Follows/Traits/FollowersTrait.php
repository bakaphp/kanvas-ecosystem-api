<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Traits;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Follows\Actions\FollowAction;
use Kanvas\Social\Follows\Actions\UnFollowAction;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Users\Models\Users;

trait FollowersTrait
{
    public function follow(Model $entity): UsersFollows
    {
        return (new FollowAction($this, $entity))->execute();
    }

    public function unFollow(Model $entity): bool
    {
        return (new UnFollowAction($this, $entity))->execute();
    }

    public function isFollowing(Model $entity): bool
    {
        return UsersFollowsRepository::isFollowing($this, $entity);
    }

    public function getFollowersCount(AppInterface $app): array
    {
        //app_2_social_count
        return $this->get('app_' . $app->getId() . '_social_count') ?? [];
    }

    public function followers(): HasManyThrough
    {
        return $this->hasManyThrough(
            Users::class,
            UsersFollows::class,
            'users_id',
            'id',
            'id',
            'users_id'
        )
        ->where('entity_namespace', $this::class)
        ->when(isset($this->companies_id), fn ($query) => $query->where('companies_id', $this->companies_id))
        ->when(isset($this->companies_branches_id), fn ($query) => $query->where('companies_branches_id', $this->companies_branches_id));
    }
}
