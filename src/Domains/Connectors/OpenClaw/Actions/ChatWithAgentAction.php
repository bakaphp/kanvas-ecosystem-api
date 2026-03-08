<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\OpenClaw\Enums\CustomFieldEnum;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;

class ChatWithAgentAction
{
    public function __construct(
        protected Agent $agent,
        protected CompanyInterface $company,
        protected string $message,
    ) {
    }

    public function execute(): string
    {
        $agentId = $this->agent->get(CustomFieldEnum::OPENCLAW_AGENT_ID->value);

        if (empty($agentId)) {
            throw new ValidationException('Agent has not been deployed to OpenClaw');
        }

        $client = new SshClient($this->company);

        try {
            $response = $client->exec(
                'openclaw agent --agent ' . escapeshellarg((string) $agentId)
                . ' --message ' . escapeshellarg($this->message)
            );
        } finally {
            $client->disconnect();
        }

        return trim($response);
    }
}
