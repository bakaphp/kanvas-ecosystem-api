<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Types;

use Kanvas\Connectors\OpenClaw\Actions\ChatWithAgentAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

class OpenClawAgentHandler
{
    protected Agent $agent;

    public function setAgent(Agent $agent): void
    {
        $this->agent = $agent;
    }

    public function chat(string $message, ?string $sessionKey = null): string
    {
        $activeDeployment = $this->agent->activeDeployment;

        if (! $activeDeployment instanceof AgentDeployment || ! $activeDeployment->isRunning()) {
            throw new ValidationException('Agent does not have an active Docker deployment');
        }

        return new ChatWithAgentAction(
            $this->agent,
            $message,
            $sessionKey,
        )->execute();
    }
}
