<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Guild\Customers\Actions\CreatePeopleByEmailAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Repositories\UsersRepository;

/**
 * Who wrote to the agent, as an entity the conversation can hang off — a teammate, the CRM person
 * behind the address, or that person's live lead. Returns null when the sender is a stranger and the
 * mailbox is RESTRICTED: without an entity there is no identity to trust with the agent's tools.
 */
class AgentInboxSenderResolverService
{
    public function __construct(
        private readonly Agent $agent,
    ) {
    }

    public function resolve(string $email, ?string $displayName = null): ?Model
    {
        $app = $this->agent->app;
        $company = $this->agent->company;

        try {
            $user = UsersRepository::getUserOfAppByEmail($email, $app);
            UsersRepository::belongsToCompany($user, $company);

            return $user;
        } catch (ModelNotFoundException | ExceptionsModelNotFoundException) {
            // Not a teammate — fall through to the CRM side.
        }

        $people = PeoplesRepository::getByEmail($email, $company, $app);

        if ($people instanceof People) {
            // The live lead when there is one: it carries the AI-mode switch, the follow-up state and
            // the email thread anchor the responder needs to answer in context.
            return LeadsRepository::getPeopleActiveLead($people) ?? $people;
        }

        if (! new AgentMailboxService()->accessFor($this->agent)->allowsUnknownSenders()) {
            return null;
        }

        return new CreatePeopleByEmailAction(
            $email,
            $this->agent->user,
            $company,
            $displayName,
        )->execute();
    }
}
