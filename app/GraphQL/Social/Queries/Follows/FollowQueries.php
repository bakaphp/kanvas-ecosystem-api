<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Queries\Follows;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Recombee\Actions\RecommendUsersToFollowByInterestsAction;
use Kanvas\Connectors\Recombee\Actions\RecommendUsersToFollowByPostsCategoriesAction;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class FollowQueries
{
    /**
     * isFollowing
     */
    public function isFollowing(mixed $root, array $request): bool
    {
        $app = apps(Apps::class);
        $whoIsFollowing = UsersRepository::getUserOfAppById((int) $request['user_id'], $app);
        $user = auth()->user();

        return $user->isFollowing($whoIsFollowing);
    }

    /**
     * getTotalFollowers
     */
    public function getTotalFollowers(mixed $root, array $request): int
    {
        $app = apps(Apps::class);
        $user = UsersRepository::getUserOfAppById((int) $request['user_id'], $app);

        return UsersFollowsRepository::getTotalFollowers($user);
    }

    /**
     * getTotalFollowers
     */
    public function getRecommendedUsers(mixed $root, array $request): Builder
    {
        $app = apps(Apps::class);
        $user = UsersRepository::getUserOfAppById((int) $request['user_id'], $app);
        $company = $user->getCurrentCompany();

        /**
         * @todo
         * 
         * this right now is tied to recombee, we need to make it more generic
         * and allow to use other recommendation engines
         */
        $entityIdsByInterests = (new RecommendUsersToFollowByInterestsAction($app, $company, $user))->execute();
        $entityIdsUsersPostSimilarCategories = (new RecommendUsersToFollowByPostsCategoriesAction($app, $company, $user))->execute();

        $entityIds = array_unique(array_merge($entityIdsByInterests, $entityIdsUsersPostSimilarCategories));

        $usersToFollow = Users::query()
            ->whereIn('id', $entityIds)
            ->where('is_deleted', 0);

        return $usersToFollow;
    }
}
