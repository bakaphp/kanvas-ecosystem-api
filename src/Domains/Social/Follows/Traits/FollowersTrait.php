<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Traits;

use Baka\Contracts\AppInterface;
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
    public function follow(Model $entity, ?AppInterface $app = null): UsersFollows
    {
        return (new FollowAction($this, $entity))->execute();
    }

    public function unFollow(Model $entity, ?AppInterface $app = null): bool
    {
        return (new UnFollowAction($this, $entity, $app))->execute();
    }

    public function isFollowing(Model $entity, ?AppInterface $app = null): bool
    {
        return UsersFollowsRepository::isFollowing($this, $entity, $app);
    }

    public function getFollowersCount(AppInterface $app): array
    {
        //app_2_social_count
        return $this->get('app_' . $app->getId() . '_social_count') ?? [];
    }

    public function getTotalFollowing(AppInterface $app): int
    {
        return $this->getFollowersCount($app)['users_following_count'] ?? 0;
    }

    public function getTotalFollowers(AppInterface $app): int
    {
        return $this->getFollowersCount($app)['users_followers_count'] ?? 0;
    }

    public function resetSocialCount(AppInterface $app): void
    {
        $key = "app_{$app->getId()}_social_count";
        $socialCount = $this->get($key);

        $className = strtolower(class_basename(Users::class));
        $followingIndex = "{$className}_following_count";
        $followersIndex = "{$className}_followers_count";

        $totalFollower = UsersFollowsRepository::getUserFollowerBuilder($this, $app)->count();
        $totalFollowing = UsersFollowsRepository::getUserFollowingBuilder($this, $app)->count();

        $socialCount[$followingIndex] = $totalFollowing;
        $socialCount[$followersIndex] = $totalFollower;

        $this->set($key, $socialCount);
    }

    public function followers(): HasManyThrough
    {
        $app = app(Apps::class);

        return $this->hasManyThrough(
            Users::class,
            UsersFollows::class,
            'users_id',
            'id',
            'id',
            'users_id'
        )
        ->where('apps_id', $app->getId())
        ->where('entity_namespace', $this::class)
        ->when(isset($this->companies_id), fn ($query) => $query->where('companies_id', $this->companies_id))
        ->when(isset($this->companies_branches_id), fn ($query) => $query->where('companies_branches_id', $this->companies_branches_id));
    }
}
