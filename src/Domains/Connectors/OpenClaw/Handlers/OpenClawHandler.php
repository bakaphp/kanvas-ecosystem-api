<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Exceptions\ValidationException;
use Override;

class OpenClawHandler extends BaseIntegration
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

        $this->company->set('openclaw_ssh_host', $sshHost);
        $this->company->set('openclaw_ssh_port', $this->data['ssh_port'] ?? 22);
        $this->company->set('openclaw_ssh_user', $sshUser);
        $this->company->set('openclaw_ssh_private_key', $sshPrivateKey);
        $this->company->set('openclaw_home', $this->data['openclaw_home'] ?? '~/.openclaw');
        $this->company->set('openclaw_gateway_token', $this->data['gateway_token'] ?? '');

        $client = new SshClient($this->app, $this->company);
        $status = $client->getGatewayStatus();
        $client->disconnect();

        if (str_contains($status, 'error') || str_contains($status, 'not found')) {
            throw new ValidationException('OpenClaw gateway is not running: ' . $status);
        }

        return true;
    }
}
