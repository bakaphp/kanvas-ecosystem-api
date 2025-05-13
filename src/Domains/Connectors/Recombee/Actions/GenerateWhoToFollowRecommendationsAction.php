<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Recombee\Enums\ScenariosEnum;
use Kanvas\Connectors\Recombee\Services\RecombeeUserRecommendationService;
use Kanvas\Users\Models\Users;

class GenerateWhoToFollowRecommendationsAction
{
    public function __construct(
        protected AppInterface $app,
        protected ?CompanyInterface $company = null
    ) {}

    public function execute(UserInterface $user, int $pageSize = 10, string $scenario = ScenariosEnum::USER_FOLLOW_SUGGETIONS_SIMILAR_INTERESTS->value): Builder
    {
        $socialConnection = config('database.connections.social.database');
        $recommendationService = new RecombeeUserRecommendationService($this->app);

        $response = $recommendationService->getUserToUserRecommendation($user, $pageSize, $scenario);

        $entityIds = collect($response['recomms'])
            ->pluck('id')
            ->unique()
            ->filter()
            ->toArray();

        return Users::whereIn('users.id', $entityIds)
            ->whereNotExists(function ($query) use ($user) {
                $query->select(DB::raw(1))
                    ->from('users_follows')
                    ->where('users_follows.apps_id', $this->app->getId())
                    ->where('users_follows.is_deleted', 0)
                    ->where('users_follows.users_id', $user->id)
                    ->where('users_follows.entity_namespace', Users::class)
                    ->whereRaw('users_follows.entity_id = users.id');
            })
            ->select('users.*')
            ->get();
    }
}
