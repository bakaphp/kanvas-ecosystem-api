<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Scan a machine for active Hermes installations and return the list of
 * Linux usernames that have a valid .hermes/docker-compose.yml.
 *
 * The actual per-user update is handled by UpdateHermesForUserJob so each
 * installation gets its own timeout, retry budget, and failure isolation.
 */
class UpdateHermesOnMachineAction
{
    public function __construct(
        protected AgentMachine $machine,
    ) {
    }

    /**
     * @return string[]
     */
    public function execute(): array
    {
        $client = SshClient::fromMachine($this->machine);

        try {
            $users = $this->scanUsers($client);
        } finally {
            $client->disconnect();
        }

        if (empty($users)) {
            throw new ValidationException('No Hermes installations found on machine: ' . $this->machine->name);
        }

        return $users;
    }

    /**
     * Return usernames whose home directory contains .hermes/docker-compose.yml.
     *
     * @return string[]
     */
    private function scanUsers(SshClient $client): array
    {
        $raw = $client->exec(
            'sudo bash -c \''
            . 'for dir in /home/*/; do'
            . ' user=$(basename "$dir");'
            . ' if [ -f "${dir}.hermes/docker-compose.yml" ]; then'
            . ' echo "__USER__${user}";'
            . ' fi;'
            . ' done'
            . '\'',
            10
        );

        return array_values(array_filter(
            array_map(
                fn (string $line) => str_starts_with(trim($line), '__USER__')
                    ? substr(trim($line), strlen('__USER__'))
                    : null,
                explode("\n", $raw)
            )
        ));
    }
}
