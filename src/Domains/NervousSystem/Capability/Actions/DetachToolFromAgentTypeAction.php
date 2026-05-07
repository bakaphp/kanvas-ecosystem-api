<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Actions;

use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Capability\Models\Tool;

class DetachToolFromAgentTypeAction
{
    public function __construct(
        protected readonly Tool $tool,
        protected readonly AgentType $agentType,
    ) {
    }

    public function execute(): bool
    {
        $this->tool->agentTypes()->detach($this->agentType->getId());

        return true;
    }
}
