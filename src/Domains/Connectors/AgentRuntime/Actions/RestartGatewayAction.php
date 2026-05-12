<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AgentRuntime\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\AgentRuntime\SshClient;

class RestartGatewayAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
    ) {
    }

    public function execute(): string
    {
        $client = new SshClient($this->app, $this->company);

        try {
            return trim($client->restartGateway());
        } finally {
            $client->disconnect();
        }
    }
}
