<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\Enums\DeploymentStatusEnum;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

class TerminateAgentOnMachineAction
{
    public function __construct(
        protected AgentDeployment $deployment,
    ) {
    }

    public function execute(): bool
    {
        if ($this->deployment->status === DeploymentStatusEnum::TERMINATED->value) {
            throw new ValidationException('Deployment is already terminated');
        }

        $machine = $this->deployment->machine;
        $client = SshClient::fromMachine($machine);

        try {
            $openclawDir = $this->deployment->home_directory . '/.openclaw';
            $systemUser = $this->deployment->system_user;

            $client->exec(
                'sudo -u ' . escapeshellarg($systemUser)
                . ' bash -c ' . escapeshellarg('cd ' . $openclawDir . ' && docker compose down --rmi local 2>&1')
            );

            $client->exec('sudo userdel -r ' . escapeshellarg($systemUser) . ' 2>/dev/null || true');

            $this->deployment->status = DeploymentStatusEnum::TERMINATED->value;
            $this->deployment->terminated_at = now();
            $this->deployment->saveOrFail();

            $this->deployment->agent->update(['deployment_status' => 'pending']);
        } finally {
            $client->disconnect();
        }

        return true;
    }
}
