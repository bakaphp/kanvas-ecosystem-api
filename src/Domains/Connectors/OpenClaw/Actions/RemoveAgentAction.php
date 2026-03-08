<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\OpenClaw\Enums\CustomFieldEnum;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\Agents\Models\Agent;

class RemoveAgentAction
{
    public function __construct(
        protected Agent $agent,
        protected AppInterface $app,
        protected CompanyInterface $company,
    ) {
    }

    public function execute(): bool
    {
        $agentId = $this->agent->get(CustomFieldEnum::OPENCLAW_AGENT_ID->value);

        if (empty($agentId)) {
            return true;
        }

        $client = new SshClient($this->app, $this->company);

        try {
            $client->cli('agents delete ' . escapeshellarg((string) $agentId) . ' --force');
            $this->agent->update(['deployment_status' => 'pending']);
        } finally {
            $client->disconnect();
        }

        return true;
    }
}
