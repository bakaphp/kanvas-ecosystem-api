<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\OpenClaw\SshClient;

class ListAgentsAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
    ) {
    }

    /**
     * @return array<array-key, mixed>
     */
    public function execute(): array
    {
        $client = new SshClient($this->app, $this->company);

        try {
            $output = trim($client->listAgents());
            $decoded = json_decode($output, true);

            return is_array($decoded) ? $decoded : ['raw' => $output];
        } finally {
            $client->disconnect();
        }
    }
}
