<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness\Fakes;

use Kanvas\Connectors\OpenCode\SshClient;

/**
 * Answers `exec()` from a prefix → output map and records every command, so an action's docker
 * conversation can be asserted on without a socket.
 */
class FakeCodingSshClient extends SshClient
{
    /** @var list<string> */
    public array $commands = [];

    /**
     * @param array<string, string> $responses command prefix => output
     */
    public function __construct(private readonly array $responses = [])
    {
        // Never opens a connection, so the parent constructor is deliberately skipped.
    }

    public function exec(string $command, int $timeout = 30): string
    {
        $this->commands[] = $command;

        foreach ($this->responses as $prefix => $output) {
            if (str_starts_with($command, $prefix)) {
                return $output;
            }
        }

        return '';
    }

    public function disconnect(): void
    {
    }

    /**
     * @return list<string>
     */
    public function removedContainers(): array
    {
        $names = [];

        foreach ($this->commands as $command) {
            if (preg_match("/^docker rm -f '([^']+)'/", $command, $matches) === 1) {
                $names[] = $matches[1];
            }
        }

        return $names;
    }
}
