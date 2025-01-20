<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\UsersRatings;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\UsersRatings\Actions\CreateUserRating;
use Kanvas\Social\UsersRatings\DataTransferObject\UserRating as UsersRatingsDTO;
use Kanvas\Social\UsersRatings\Models\UserRating;
use Kanvas\SystemModules\Models\SystemModules;

class UserRatingManagement
{
    public function create(mixed $root, array $request): UserRating
    {
        $input = $request['input'];

        $usersRatingsDTO = new UsersRatingsDTO(
            app(Apps::class),
            auth()->user(),
            auth()->user()->getCurrentCompany(),
            SystemModules::getById($input['system_module_id'], app(Apps::class)),
            (int)$input['entity_id'],
            $input['rating'],
            $input['comment']
        );

        return (new CreateUserRating($usersRatingsDTO))->execute();
    }
}
