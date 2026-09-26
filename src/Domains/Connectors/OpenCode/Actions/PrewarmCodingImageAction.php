<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenCode\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Pulls the coding image onto a machine ahead of time.
 *
 * Pull-only by design: the older runtime *builds* its image on the machine with a 900-second budget,
 * which a per-task container cannot pay. Without a prewarm the first task on a fresh machine still
 * waits for a ~500 MB pull inside its own launch, which reads to a user as a hung job.
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
        $image = Str::trimToNull((string) $this->app->get(ConfigurationEnum::IMAGE->value));

        if ($image === null) {
            throw new ValidationException('No opencode image configured (' . ConfigurationEnum::IMAGE->value . ')');
        }

        $client = SshClient::fromMachine($this->machine);

        try {
            $result = $client->exec('docker pull ' . escapeshellarg($image) . ' 2>&1; echo "EXIT_CODE:$?"', 900);

            if (! str_contains($result, 'EXIT_CODE:0')) {
                throw new ValidationException('Could not pull ' . $image . ': ' . trim($result));
            }
        } finally {
            $client->disconnect();
        }

        return $image;
    }
}
