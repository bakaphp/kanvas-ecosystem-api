<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Actions;

use Kanvas\Connectors\OpenCode\Runtime\CodingRuntimeDriverFactory;
use Kanvas\Connectors\OpenCode\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Installs the coding runtime onto a machine ahead of time, so the first task on a fresh machine does
 * not pay for it inside its own launch — which reads to a user as a hung job.
 */
class PrewarmCodingImageAction
{
    public function __construct(
        private readonly AgentMachine $machine,
    ) {
    }

    public function execute(): string
    {
        $app = $this->machine->app;

        if ($app === null) {
            throw new ValidationException('Machine ' . $this->machine->name . ' belongs to no app.');
        }

        $client = SshClient::fromMachine($this->machine);

        try {
            return CodingRuntimeDriverFactory::forMachine($this->machine)->prewarm($app, $client);
        } finally {
            $client->disconnect();
        }
    }
}
