<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\UsersRatings;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\UsersRatings\Actions\CreateUsersRatings;
use Kanvas\Social\UsersRatings\DataTransferObject\UsersRatings as UsersRatingsDTO;
use Kanvas\Social\UsersRatings\Models\UsersRatings;
use Kanvas\SystemModules\Models\SystemModules;

class UsersRatingsManagement
{
    public function create(mixed $root, array $request): UsersRatings
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

        return (new CreateUsersRatings($usersRatingsDTO))->execute();
    }
}
