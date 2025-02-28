<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Queries\Follows;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Recombee\Actions\GenerateRecommendUsersToFollowAction;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Users\Repositories\UsersRepository;

class FollowQueries
{
    /**
     * isFollowing
     */
    public function isFollowing(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $whoIsFollowing = UsersRepository::getUserOfAppById((int) $request['user_id'], $app);
        $user = auth()->user();

        return $user->isFollowing($whoIsFollowing);
    }

    /**
     * getTotalFollowers
     */
    public function getTotalFollowers(mixed $root, array $request): int
    {
        $app = app(Apps::class);
        $user = UsersRepository::getUserOfAppById((int) $request['user_id'], $app);

        return UsersFollowsRepository::getTotalFollowers($user);
    }

    /**
     * getTotalFollowers
     */
    public function getRecommendedUsers(mixed $root, array $request): Builder
    {
        $app = app(Apps::class);
        $auth = auth()->user();
        $user = UsersRepository::getUserOfAppById((int) $request['user_id'], $app);
        $company = $user->getCurrentCompany();

        if (! $auth->isAdmin()) {
            $user = $auth;
        }

        $generateUserToUserRecommendation = new GenerateRecommendUsersToFollowAction($app, $company, $user);

        return $generateUserToUserRecommendation->execute(10);
    }
}
