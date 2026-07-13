<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\DataTransferObject\Agent as AgentData;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Users\Models\Users;

class CloneAgentForCompanyAction
{
    public function __construct(
        private readonly Agent $source,
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Users $owner,
    ) {
    }

    public function execute(): Agent
    {
        /** @var array<int, Tool> $tools */
        $tools = $this->source->selectedTools()->get()->all();

        $data = new AgentData(
            app: $this->app,
            company: $this->company,
            user: $this->owner,
            agentType: $this->source->type,
            name: $this->source->name,
            role: $this->source->role ?? [],
            is_active: true,
            description: $this->source->description,
            config: $this->source->config,
            soul: $this->source->soul,
            instructions: $this->source->instructions,
            outputFormat: $this->source->output_format,
            identity: $this->source->identity,
            userContext: $this->source->user_context,
            toolsConfig: $this->source->tools_config,
            tools: $tools !== [] ? $tools : null,
            createdBy: $this->owner,
        );

        return new CreateAgentAction($data)->execute();
    }
}
