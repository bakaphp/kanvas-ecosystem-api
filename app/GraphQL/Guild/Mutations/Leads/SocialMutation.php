<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Follows\Models\UsersFollows;

class SocialMutation
{
    /**
     * follow a lead
     */
    public function follow(mixed $root, array $req): bool
    {
        $user = auth()->user();
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
        $user = auth()->user();
        $lead = Lead::getByUuidFromBranch(
            $req['input']['entity_id'],
            $user->getCurrentBranch()
        );

        return $user->unFollow($lead);
    }
}
