<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\DataTransferObject\Agent as AgentData;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Users\Models\Users;

/**
 * Create a working teammate agent: its own user, its own instructions, its own tools.
 *
 * **The dedicated user is the point, not a detail.** Agents that share a user share an identity, and
 * two things break at once: ledger memory accrues to whoever else holds that user, and any guard that
 * asks "did this agent produce this record?" answers yes for work the agent never did. The channel
 * wake guard compares a record's `users_id` against the agent's — point a new agent at the user that
 * owns the WhatsApp receiver and it skips every inbound message rather than just its own output,
 * silently, with no error anywhere.
 *
 * A true agent, never a sub-agent: a sub-agent is a private function of its parent, not a teammate
 * that can be assigned work and hold a conversation.
 */
class HireAgentAction
{
    /**
     * @param list<Tool> $tools
     */
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Users $hiredBy,
        private readonly ?Agent $hiredByAgent,
        private readonly AgentType $agentType,
        private readonly string $name,
        private readonly string $role,
        private readonly string $instructions,
        private readonly array $tools = [],
        private readonly ?string $soul = null,
        private readonly ?string $outputFormat = null,
    ) {
    }

    public function execute(): Agent
    {
        $name = trim($this->name);

        if ($name === '') {
            throw new ValidationException('An agent needs a name.');
        }

        if (trim($this->instructions) === '') {
            throw new ValidationException(
                'An agent needs instructions — without them it has tools and no job.'
            );
        }

        $existing = Agent::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->where('is_deleted', 0)
            ->first();

        if ($existing !== null) {
            throw new ValidationException(sprintf(
                'This company already has an agent called "%s" (id %d). Retune that one instead of '
                . 'hiring a second with the same name.',
                $existing->name,
                $existing->getId()
            ));
        }

        return new CreateAgentAction(new AgentData(
            app: $this->app,
            company: $this->company,
            user: new ProvisionAgentUserAction($this->app, $this->company, $name)->execute(),
            agentType: $this->agentType,
            name: $name,
            role: trim($this->role) !== '' ? trim($this->role) : $name,
            is_active: true,
            soul: $this->soul,
            instructions: $this->instructions,
            outputFormat: $this->outputFormat,
            tools: $this->tools,
            // Lineage only — `isSubAgent` stays false, so no SUB_AGENT tool row is minted and the hire
            // is a teammate, not a callable function of its hirer. The link is what lets the hirer
            // retune it later: without a recorded relationship the correction loop only reaches agents
            // that happen to share a project.
            parentAgent: $this->hiredByAgent,
            createdBy: $this->hiredBy,
            isSubAgent: false,
        ))->execute();
    }
}
