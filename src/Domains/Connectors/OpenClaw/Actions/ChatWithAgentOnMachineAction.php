<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Chat with an agent deployed on a remote machine via SSH + docker exec.
 *
 * SSHs into the deployment's machine and runs the OpenClaw CLI inside the
 * agent's container: `docker exec {container} node /app/dist/index.js agent --agent {slug} --message {msg}`
 *
 * The CLI entrypoint is `node /app/dist/index.js` (not `openclaw` which is not in PATH).
 * The `--agent {slug}` flag routes to the correct agent config.
 * Timeout is 120s because LLM API calls (especially Gemini) can take 30-60s.
 */
class ChatWithAgentOnMachineAction
{
    public function __construct(
        protected Agent $agent,
        protected string $message,
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
            $response = $client->exec(
                'docker exec ' . escapeshellarg($deployment->container_name)
                . ' node /app/dist/index.js agent'
                . ' --agent ' . escapeshellarg($this->agent->slug)
                . ' --message ' . escapeshellarg($this->message)
                . ' 2>&1',
                120,
            );
        } finally {
            $client->disconnect();
        }

        return trim($response);
    }
}
