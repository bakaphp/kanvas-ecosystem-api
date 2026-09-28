<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Enums;

/**
 * Bouncer abilities that authorize an agent's highest-blast-radius tools without making it an admin.
 *
 * An agent working autonomously has no human in the turn to authorize against — the wake jobs run it
 * on its own user. So the agent's own grant has to be the authorization, and the only alternative to
 * these named abilities is making the agent a tenant administrator, which grants it everything else
 * on the way to granting it one thing.
 *
 * Grant with `Bouncer::allow($role)->to(AgentAbilityEnum::HIRE_AGENT->value)` inside the app's scope.
 */
enum AgentAbilityEnum: string
{
    case HIRE_AGENT = 'agent-hire';
    case MANAGE_COMPANY_WORKFLOWS = 'agent-manage-company-workflows';
}
