<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Queries\Follows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Connectors\Recombee\Actions\RecommendUsersToFollowByPostsCategoriesAction;
use Kanvas\Connectors\Recombee\Actions\RecommendUsersToFollowByInterestsAction;
use Kanvas\Users\Models\Users;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Users\Models\UsersAssociatedApps;

class FollowQueries
{
    /**
     * isFollowing
     */
    public function isFollowing(mixed $root, array $request): bool
    {
        $whoIsFollowing = UsersRepository::getUserOfAppById((int) $request['user_id']);
        $user = auth()->user();
        return $user->isFollowing($whoIsFollowing);
    }

    /**
     * getTotalFollowers
     */
    public function getTotalFollowers(mixed $root, array $request): int
    {
        $user = UsersRepository::getUserOfAppById((int) $request['user_id']);

        return UsersFollowsRepository::getTotalFollowers($user);
    }

    /**
     * getTotalFollowers
     */
    public function getRecommendedUsers(mixed $root, array $request): Builder
    {
        $app = Apps::find(78);
        $company = Companies::find(2626);
        $user = Users::find($request['user_id']);

        $entityIdsByInterests = (new RecommendUsersToFollowByInterestsAction($app, $company, $user))->execute();
        $entityIdsUsersPostSimilarCategories = (new RecommendUsersToFollowByPostsCategoriesAction($app, $company, $user))->execute();

        $entityIds = array_unique(array_merge($entityIdsByInterests, $entityIdsUsersPostSimilarCategories));

        $usersToFollow = Users::query()
            ->whereIn('id', $entityIds)
            ->where('is_deleted', 0);

        return $usersToFollow;
    }
}
