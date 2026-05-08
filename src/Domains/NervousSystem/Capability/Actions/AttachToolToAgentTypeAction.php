<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Actions;

use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Capability\Models\Tool;

class AttachToolToAgentTypeAction
{
    public function __construct(
        protected readonly Tool $tool,
        protected readonly AgentType $agentType,
    ) {
    }

    public function execute(): Tool
    {
        $this->tool->agentTypes()->syncWithoutDetaching([$this->agentType->getId()]);

        return $this->tool;
    }
}
