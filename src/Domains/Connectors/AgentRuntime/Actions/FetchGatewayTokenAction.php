<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AgentRuntime\Actions;

use Kanvas\Connectors\AgentRuntime\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Read the OpenClaw gateway auth token from a deployment's container.
 *
 * The token lives in /home/node/.openclaw/openclaw.json on the container's
 * filesystem. We SSH into the host and `docker exec cat` the file, then pluck
 * the token out of the JSON tree.
 *
 * Used in two places:
 *  - Lazy fallback in ChatWithAgentAction when the deployment has no cached token
 *    (covers pre-existing deployments that predate the custom field)
 *  - On-demand refresh when the gateway returns 401 (rotation / stale cache)
 */
class FetchGatewayTokenAction
{
    public function __construct(
        protected AgentDeployment $deployment,
    ) {
    }

    public function execute(): string
    {
        $client = SshClient::fromMachine($this->deployment->machine);

        try {
            $json = $client->exec(
                'docker exec ' . escapeshellarg($this->deployment->container_name)
                . ' cat /home/node/.openclaw/openclaw.json 2>&1',
                30,
            );
        } finally {
            $client->disconnect();
        }

        $config = json_decode(trim($json), true);

        if (! is_array($config)) {
            throw new ValidationException(
                'Could not read openclaw.json from container ' . $this->deployment->container_name . ': ' . $json
            );
        }

        $gateway = $config['gateway'] ?? null;
        $token = null;

        if (is_array($gateway) && isset($gateway['auth']) && is_array($gateway['auth'])) {
            $token = $gateway['auth']['token'] ?? null;
        }

        if ($token === null && isset($config['auth']) && is_array($config['auth'])) {
            $token = $config['auth']['token'] ?? null;
        }

        if (! is_string($token) || $token === '') {
            throw new ValidationException(
                'Gateway auth token not found in openclaw.json for deployment ' . (string) $this->deployment->getId()
            );
        }

        return $token;
    }
}
