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

    /**
     * @param list<string> $images URLs (https://… or data:image/…;base64,…) passed straight through
     *                             to the gateway as multimodal `input_image` items. See
     *                             ChatWithAgentAction::buildInput for the payload shape.
     */
    public function chat(string $message, ?string $sessionKey = null, array $images = []): string
    {
        $activeDeployment = $this->agent->activeDeployment;

        if (! $activeDeployment instanceof AgentDeployment || ! $activeDeployment->isRunning()) {
            throw new ValidationException('Agent does not have an active Docker deployment');
        }

        return new ChatWithAgentAction(
            $this->agent,
            $message,
            $sessionKey,
            $images,
        )->execute();
    }
}
