<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\UsersRatings;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\UsersRatings\Actions\CreateUserRatingAction;
use Kanvas\Social\UsersRatings\DataTransferObject\UserRating as DataTransferObjectUserRating;
use Kanvas\Social\UsersRatings\Models\UserRating;
use Kanvas\SystemModules\Models\SystemModules;

class UserRatingManagement
{
    public function create(mixed $root, array $request): UserRating
    {
        $input = $request['input'];
        $app = app(Apps::class);

        $usersRatingsDTO = new DataTransferObjectUserRating(
            $app,
            auth()->user(),
            auth()->user()->getCurrentCompany(),
            SystemModules::getById($input['system_module_id'], $app),
            (int) $input['entity_id'],
            $input['rating'],
            $input['comment']
        );

        return (new CreateUserRatingAction($usersRatingsDTO))->execute();
    }
}
