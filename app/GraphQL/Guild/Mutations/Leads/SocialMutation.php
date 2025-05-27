<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class SocialMutation
{
    /**
     * follow a lead
     */
    public function follow(mixed $root, array $req): bool
    {
        $app = app(Apps::class);
        $userId = $req['input']['user_id'];
        $user = auth()->user();

        $user = Users::getById($userId);

        UsersRepository::belongsToThisApp($user, $app);

        $lead = Lead::getByUuidFromBranch(
            $req['input']['entity_id'],
            $user->getCurrentBranch()
        );

        return $user->follow($lead) instanceof UsersFollows;
    }

    /**
     * unFollow a lead
     */
    public function unFollow(mixed $root, array $req): bool
    {
        $app = app(Apps::class);
        $userId = $req['input']['user_id'];
        $user = auth()->user();

        $user = Users::getById($userId);

        UsersRepository::belongsToThisApp($user, $app);
        $lead = Lead::getByUuidFromBranch(
            $req['input']['entity_id'],
            $user->getCurrentBranch()
        );

        return $user->unFollow($lead);
    }
}
