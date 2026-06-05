<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Hermes\Enums\ConfigurationEnum;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\CreateAgentMachineAction;
use Kanvas\Intelligence\Agents\DataTransferObject\AgentMachine as AgentMachineData;
use Override;

/**
 * Integration handler for Hermes setup.
 *
 * Creates an AgentMachine record from the provided SSH credentials and validates
 * connectivity. SSH credentials live on the AgentMachine model — the only
 * company-level settings stored are the default machine ID and gateway token.
 *
 * Mirrors OpenClawHandler exactly; the two only differ in the Configuration/SshClient
 * namespaces they wire up. Lift to a base handler if a third provider arrives.
 */
class HermesHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $sshHost = (string) ($this->data['ssh_host'] ?? '');
        $sshUser = (string) ($this->data['ssh_user'] ?? '');
        $sshPrivateKey = (string) ($this->data['ssh_private_key'] ?? '');

        if ($sshHost === '' || $sshUser === '' || $sshPrivateKey === '') {
            throw new ValidationException('SSH host, user, and private key are required');
        }

        $sshPort = (int) ($this->data['ssh_port'] ?? 22);
        $gatewayToken = (string) ($this->data['gateway_token'] ?? '');

        $machine = new CreateAgentMachineAction(
            new AgentMachineData(
                app: $this->app,
                company: $this->company,
                name: (string) ($this->data['machine_name'] ?? 'Machine ' . $sshHost),
                host: $sshHost,
                ssh_user: $sshUser,
                ssh_private_key: $sshPrivateKey,
                ssh_port: $sshPort,
                region: (string) ($this->data['region'] ?? ''),
                port_range_start: (int) ($this->data['port_range_start'] ?? 20000),
                port_range_end: (int) ($this->data['port_range_end'] ?? 30000),
                max_agents: (int) ($this->data['max_agents'] ?? 100),
            ),
        )->execute();

        // Validate SSH connectivity
        $client = SshClient::fromMachine($machine);
        $client->exec('echo ok');
        $client->disconnect();

        $this->company->set(ConfigurationEnum::DEFAULT_MACHINE_ID->value, $machine->getId());
        $this->company->set(ConfigurationEnum::GATEWAY_TOKEN->value, $gatewayToken);

        return true;
    }
}
