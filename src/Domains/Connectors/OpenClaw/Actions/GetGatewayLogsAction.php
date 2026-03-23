<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\OpenClaw\SshClient;

class GetGatewayLogsAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected int $lines = 100,
    ) {
    }

    public function execute(): string
    {
        $client = new SshClient($this->app, $this->company);

        try {
            return trim($client->getGatewayLogs($this->lines));
        } finally {
            $client->disconnect();
        }
    }
}
