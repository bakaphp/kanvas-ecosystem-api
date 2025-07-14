<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Connectors\Recombee\Enums\ScenariosEnum;
use Kanvas\Connectors\Recombee\Services\RecombeeUserRecommendationService;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;

class GenerateWhoToFollowRecommendationsAction
{
    public function __construct(
        protected AppInterface $app,
        protected ?CompanyInterface $company = null
    ) {
    }

    public function execute(UserInterface $user, int $pageSize = 10, string $scenario = ScenariosEnum::USER_FOLLOW_SUGGETIONS_SIMILAR_INTERESTS->value): Builder
    {
        $usersSystemModule = SystemModulesRepository::getByModelName(Users::class, $this->app);
        $recommendationService = new RecombeeUserRecommendationService($this->app);
        $response = $recommendationService->getUserToUserRecommendation($user, $pageSize, $scenario);

        $entityIds = collect($response['recomms'])
            ->pluck('id')
            ->unique()
            ->filter()
            ->toArray();

        $followedIds = UsersFollows::query()
            ->where('apps_id', $this->app->getId())
            ->where('is_deleted', 0)
            ->where('entity_namespace', Users::class)
            ->where('users_id', $user->getId())
            ->pluck('entity_id');

        return Users::query()
            ->join(
                "filesystem_entities",
                "filesystem_entities.entity_id",
                '=',
                'users.id'
            )
            ->whereNotIn('users.id', $followedIds)
            ->whereIn('users.id', $entityIds)
            ->where('users.id', '!=', $user->getId())
            ->where('users.is_deleted', 0)
            ->where('filesystem_entities.system_modules_id', $usersSystemModule->getId())
            ->where('filesystem_entities.field_name', 'photo');
    }
}
