<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Guild\Leads\Actions\RemoveLeadParticipantAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadsParticipant;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Follows\Models\UsersFollows;

class SocialMutation
{
    /**
     * follow a lead
     */
    public function follow(mixed $root, array $req): bool
    {
        $lead = Lead::getByUuidFromBranch(
            $req['input']['entity_id'],
            auth()->user()->getCurrentBranch()
        );

        return $lead->follow(auth()->user()) instanceof UsersFollows;
    }

    /**
     * unFollow a lead
     */
    public function unFollow(mixed $root, array $req): bool
    {
        $lead = Lead::getByUuidFromBranch(
            $req['input']['entity_id'],
            auth()->user()->getCurrentBranch()
        );

        return $lead->unFollow(auth()->user());
    }
}
