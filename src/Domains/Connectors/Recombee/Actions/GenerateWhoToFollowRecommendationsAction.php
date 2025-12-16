<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Recombee\Enums\ScenariosEnum;
use Kanvas\Connectors\Recombee\Services\RecombeeUserRecommendationService;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;

class GenerateWhoToFollowRecommendationsAction
{
    private const MIN_FOLLOWS_POPULAR_USERS = 10;
    private const MAX_SECONDS_TTL_POPULAR_USERS_CACHE = 3600;

    public function __construct(
        protected AppInterface $app,
        protected ?CompanyInterface $company = null
    ) {}

    public function execute(
        UserInterface $user,
        int $pageSize = 10,
        string $scenario = ScenariosEnum::USER_FOLLOW_SUGGESTION_SIMILAR_INTERESTS->value
    ): Builder {
        $recommendationService = new RecombeeUserRecommendationService($this->app);
        $response = $recommendationService->getUserToUserRecommendation($user, $pageSize, $scenario);

        $entityIds = collect($response['recomms'])
            ->pluck('id')
            ->unique()
            ->filter()
            ->toArray();

        $entityIds = $this->getIntersectedPopularUsersIds($entityIds);
        $followedIds = UsersFollows::query()
            ->select('entity_id')
            ->where('apps_id', $this->app->getId())
            ->where('users_id', $user->getId())
            ->where('is_deleted', 0)
            ->where('entity_namespace', Users::class)
            ->limit(100)
            ->pluck('entity_id');

        return Users::query()
            ->join('users_associated_apps', function ($join) {
                $join->on('users.id', '=', 'users_associated_apps.users_id')
                    ->where('users_associated_apps.apps_id', '=', $this->app->getId())
                    ->whereNotNull('users_associated_apps.firstname')
                    ->where('users_associated_apps.firstname', '!=', '')
                    ->where('users_associated_apps.is_deleted', 0)
                    ->where('users_associated_apps.status', 1);
            })
            ->whereNotIn('users.id', $followedIds)
            ->whereIn('users.id', $entityIds)
            ->where('users.id', '!=', $user->getId())
            ->where('users.is_deleted', 0)
            ->select('users.*');
    }

    private function getIntersectedPopularUsersIds(array $entityIds): array
    {
        $maxTtlPopularUsers = $this->app->get('max_ttl_popular_users_cache') ?? self::MAX_SECONDS_TTL_POPULAR_USERS_CACHE;
        $minFollowsPopularUsers = $this->app->get('min_follows_popular_users') ?? self::MIN_FOLLOWS_POPULAR_USERS;

        $allPopularIds = Cache::remember('popular_users_' . $this->app->getId(), now()->addHours($maxTtlPopularUsers), function () use ($minFollowsPopularUsers) {
            return UsersFollows::query()
                ->where('apps_id', $this->app->getId())
                ->where('entity_namespace', Users::class)
                ->where('is_deleted', 0)
                ->groupBy('entity_id')
                ->havingRaw('COUNT(*) >= ?', [$minFollowsPopularUsers])
                ->pluck('entity_id')
                ->all();
        });

        return array_intersect($allPopularIds, $entityIds);
    }
}
