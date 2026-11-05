<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\OpenCode\Runtime\CodingRuntimeDriverFactory;
use Kanvas\Connectors\OpenCode\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Installs the coding runtime onto a machine ahead of time, so the first task on a fresh machine does
 * not pay for it inside its own launch — which reads to a user as a hung job.
 */
class PrewarmCodingImageAction
{
    public function __construct(
        private readonly AgentMachine $machine,
        private readonly AppInterface $app,
    ) {
    }

    public function execute(): string
    {
        $client = SshClient::fromMachine($this->machine);

        try {
            return CodingRuntimeDriverFactory::forMachine($this->machine)->prewarm($this->app, $client);
        } finally {
            $client->disconnect();
        }
    }
}
