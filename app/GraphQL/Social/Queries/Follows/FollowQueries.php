<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Queries\Follows;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Recombee\Actions\GenerateWhoToFollowRecommendationsAction;
use Kanvas\Connectors\Recombee\Enums\ScenariosEnum;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Users\Repositories\UsersRepository;

class FollowQueries
{
    public function isFollowing(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $whoIsFollowing = UsersRepository::getUserOfAppById((int) $request['user_id'], $app);
        $user = auth()->user();

        return $user->isFollowing($whoIsFollowing);
    }

    public function getTotalFollowers(mixed $root, array $request): int
    {
        $app = app(Apps::class);
        $user = UsersRepository::getUserOfAppById((int) $request['user_id'], $app);

        return UsersFollowsRepository::getTotalFollowers($user);
    }

    public function getWhoToFollow(mixed $root, array $request): Builder
    {
        $app = app(Apps::class);
        $auth = auth()->user();
        $user = UsersRepository::getUserOfAppById((int) $request['user_id'], $app);
        $company = $user->getCurrentCompany();

        if (!$auth->isAdmin()) {
            $user = $auth;
        }

        /**
         * @todo this right now is tied to one service (recombee) but we should make it more generic
         * so we can use any service to get the recommendation , and change it by app
         */
        $generateUserToUserRecommendation = new GenerateWhoToFollowRecommendationsAction($app, $company);

        if ($request['static_recommendation']) {
            return $generateUserToUserRecommendation->execute($user, $request['first'] ?? 10, ScenariosEnum::STATIC_USERS_RECOMMENDATION->value);
        }

        return $generateUserToUserRecommendation->execute($user, $request['first'] ?? 10);
    }
}
