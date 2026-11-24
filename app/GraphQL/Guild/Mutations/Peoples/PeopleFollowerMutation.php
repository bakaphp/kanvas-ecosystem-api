<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Peoples;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Followers\Actions\AddFollowerAction;
use Kanvas\Guild\Followers\Actions\RemoveFollowerAction;
use Kanvas\Guild\Followers\Models\Follower;
use Kanvas\Users\Models\Users;

class PeopleFollowerMutation
{
    use ResolvesActingContext;

    public function follow(mixed $root, array $request): bool
    {
        $input = $request['input'];

        return new AddFollowerAction(
            $this->peopleFor($input),
            Users::getById($input['user_id'])
        )->execute() instanceof Follower;
    }

    public function unFollow(mixed $root, array $request): bool
    {
        $input = $request['input'];

        return new RemoveFollowerAction(
            $this->peopleFor($input),
            Users::getById($input['user_id'])
        )->execute();
    }

    /**
     * The person comes from the caller's own company; AddFollowerAction then refuses a user who is
     * not a member of it. FollowInput.entity_id is Mixed and followLead takes a uuid, so both an id
     * and a uuid are accepted here; casting a uuid to int would hit whichever id its digits spell.
     */
    private function peopleFor(array $input): People
    {
        $ctx = $this->actingContext();
        $entityId = (string) $input['entity_id'];

        return ctype_digit($entityId)
            ? People::getByIdFromCompanyApp((int) $entityId, $ctx->company, $ctx->app)
            : People::getByUuidFromCompanyApp($entityId, $ctx->company, $ctx->app);
    }
}
