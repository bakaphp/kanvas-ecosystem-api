<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Users;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Users\Models\BlockUser;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class BlockUserManagement
{
    public function block($rootValue, array $req): bool
    {
        $user = auth()->user();
        $blockUserId = $req['id'];
        $blockUser = Users::getById($blockUserId);
        $app = app(Apps::class);

        UsersRepository::belongsToThisApp($blockUser, $app);

        return $user->block($blockUser, $app) instanceof BlockUser;
    }

    public function unBlock($rootValue, array $req): bool
    {
        $user = auth()->user();
        $blockUserId = $req['id'];
        $blockUser = Users::getById($blockUserId);
        $app = app(Apps::class);

        UsersRepository::belongsToThisApp($blockUser, $app);

        return $user->unBlock($blockUser, $app) instanceof BlockUser;
    }
}
