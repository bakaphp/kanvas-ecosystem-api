<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Types;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenClaw\Actions\ChatWithAgentAction;
use Kanvas\Connectors\OpenClaw\Actions\ChatWithAgentOnMachineAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

class OpenClawAgentHandler
{
    protected Agent $agent;

    public function setAgent(Agent $agent): void
    {
        $this->agent = $agent;
    }

    public function chat(string $message): string
    {
        $activeDeployment = $this->agent->activeDeployment;

        if ($activeDeployment instanceof AgentDeployment && $activeDeployment->isRunning()) {
            return new ChatWithAgentOnMachineAction(
                $this->agent,
                $message,
            )->execute();
        }

        return new ChatWithAgentAction(
            $this->agent,
            app(Apps::class),
            $this->agent->company,
            $message,
        )->execute();
    }
}
