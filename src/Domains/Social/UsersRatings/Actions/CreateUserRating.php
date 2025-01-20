<?php

declare(strict_types=1);

namespace Kanvas\Social\UsersRatings\Actions;

use Kanvas\Social\UsersRatings\DataTransferObject\UserRating as UsersRatingsDTO;
use Kanvas\Social\UsersRatings\Models\UserRating;

class CreateUserRating
{
    public function __construct(
        private UsersRatingsDTO $usersRatingsDTO
    ) {
    }

    public function execute(): UserRating
    {
        return UserRating::updateOrCreate([
            'users_id' => $this->usersRatingsDTO->user->getId(),
            'companies_id' => $this->usersRatingsDTO->company->getId(),
            'apps_id' => $this->usersRatingsDTO->app->getId(),
            'system_modules_id' => $this->usersRatingsDTO->systemModule->getId(),
            'entity_id' => $this->usersRatingsDTO->entityId,
        ], [
            'rating' => $this->usersRatingsDTO->rating,
            'comment' => $this->usersRatingsDTO->comment,
        ]);
    }
}
