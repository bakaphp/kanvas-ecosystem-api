<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Types;

use Kanvas\Connectors\OpenClaw\Actions\ChatWithAgentAction;
use Kanvas\Intelligence\Agents\Models\Agent;

class OpenClawAgentHandler
{
    protected Agent $agent;

    public function setAgent(Agent $agent): void
    {
        $this->agent = $agent;
    }

    public function chat(string $message): string
    {
        return new ChatWithAgentAction(
            $this->agent,
            $this->agent->company,
            $message,
        )->execute();
    }
}
