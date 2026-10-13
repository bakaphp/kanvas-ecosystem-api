<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

/**
 * A teammate is a user of the agent's app who belongs to the agent's company. An email an LLM typed
 * resolves to one or to nothing — never to a user of another company.
 */
trait FindsTeammates
{
    protected function teammateByEmail(string $email, Agent $agent): ?Users
    {
        try {
            $user = UsersRepository::getUserOfAppByEmail($email, $agent->app);
            UsersRepository::belongsToCompany($user, $agent->company);
        } catch (ModelNotFoundException | ExceptionsModelNotFoundException) {
            return null;
        }

        return $user;
    }
}
