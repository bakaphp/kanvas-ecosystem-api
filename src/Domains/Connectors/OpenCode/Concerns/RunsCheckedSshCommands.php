<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Concerns;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Throwable;

/**
 * Runs a host command and fails loudly when it did not work.
 *
 * `SshClient::exec()` returns whatever the command printed and says nothing about whether it succeeded,
 * so an unchecked call reads as a success on a `fatal:` — which is how a failed clone became an empty
 * checkout the agent then "worked" in. Appending the exit code to the output is the only way to recover
 * it over this transport.
 *
 * `2>&1` matters as much as the code: git says what went wrong on stderr, and a caught failure that
 * reports only "exit 128" sends whoever reads it back to the host to run the command by hand.
 */
trait RunsCheckedSshCommands
{
    private function runChecked(
        SshClient $client,
        string $command,
        string $what,
        int $timeout = 120
    ): void {
        try {
            $result = $client->exec($command . ' 2>&1; echo "EXIT_CODE:$?"', $timeout);
        } catch (Throwable $e) {
            throw new ValidationException('Could not ' . $what . ': ' . $e->getMessage());
        }

        if (! str_contains($result, 'EXIT_CODE:0')) {
            throw new ValidationException('Could not ' . $what . ': ' . trim($result));
        }
    }
}
