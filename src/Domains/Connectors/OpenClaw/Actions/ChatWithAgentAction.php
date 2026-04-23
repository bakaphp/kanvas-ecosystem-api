<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Chat with a deployed agent via SSH + docker exec into its container.
 *
 * Runs `node /app/dist/index.js agent --agent {slug} --message {msg}` inside the
 * agent's container (the `openclaw` binary is not on PATH, so the node entrypoint
 * is invoked directly). Timeout is 120s — Gemini/OpenAI calls regularly take 30–60s.
 */
class ChatWithAgentAction
{
    public function __construct(
        protected Agent $agent,
        protected string $message,
        protected ?string $sessionKey = null,
    ) {
    }

    public function execute(): string
    {
        $deployment = $this->agent->activeDeployment;

        if (! $deployment instanceof AgentDeployment || ! $deployment->isRunning()) {
            throw new ValidationException('Agent does not have an active deployment');
        }

        $client = SshClient::fromMachine($deployment->machine);

        try {
            $command = 'docker exec ' . escapeshellarg($deployment->container_name)
                . ' node /app/dist/index.js agent'
                . ' --agent ' . escapeshellarg($this->agent->slug)
                . ' --message ' . escapeshellarg($this->message);

            if ($this->sessionKey !== null && $this->sessionKey !== '') {
                $command .= ' --session-id ' . escapeshellarg($this->sessionKey);
            }

            $response = $client->exec($command . ' 2>&1', 120);
        } finally {
            $client->disconnect();
        }

        return trim($response);
    }
}
