<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Followers\Actions\AddFollowerAction;
use Kanvas\Guild\Followers\Actions\RemoveFollowerAction;
use Kanvas\Guild\Followers\Models\Follower;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class SocialMutation
{
    public function follow(mixed $root, array $req): bool
    {
        $user = $this->userFor($req['input']);

        return new AddFollowerAction($this->leadFor($req['input'], $user), $user)->execute() instanceof Follower;
    }

    public function unFollow(mixed $root, array $req): bool
    {
        $user = $this->userFor($req['input']);

        return new RemoveFollowerAction($this->leadFor($req['input'], $user), $user)->execute();
    }

    private function userFor(array $input): Users
    {
        $user = Users::getById($input['user_id']);

        UsersRepository::belongsToThisApp($user, app(Apps::class));

        return $user;
    }

    private function leadFor(array $input, Users $user): Lead
    {
        return Lead::getByUuidFromBranch($input['entity_id'], $user->getCurrentBranch());
    }
}
