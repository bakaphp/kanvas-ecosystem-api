<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness\Fakes;

use Kanvas\Connectors\OpenCode\SshClient;

/**
 * Answers `exec()` from a prefix → output map and records every command and file write, so an
 * action's host conversation can be asserted on without a socket.
 */
class FakeCodingSshClient extends SshClient
{
    /** @var list<string> */
    public array $commands = [];

    /** @var array<string, string> remote path => contents */
    public array $files = [];

    /** What `writeFile()` answers; false stands in for an SFTP that refused the write. */
    public bool $writable = true;

    /**
     * @param array<string, string> $responses command prefix => output
     */
    public function __construct(private readonly array $responses = [])
    {
        // Never opens a connection, so the parent constructor is deliberately skipped.
    }

    /**
     * The longest matching prefix wins, so `docker ps -aq` is answered by its own entry and not by
     * `docker ps -a` — a poll that was answered with the listing read as "still there" for 15 seconds.
     */
    public function exec(string $command, int $timeout = 30): string
    {
        $this->commands[] = $command;

        $best = null;

        foreach ($this->responses as $prefix => $output) {
            if (str_starts_with($command, (string) $prefix) && ($best === null || strlen((string) $prefix) > strlen($best))) {
                $best = (string) $prefix;
            }
        }

        return $best === null ? '' : $this->responses[$best];
    }

    public function writeFile(string $remotePath, string $content): bool
    {
        $this->files[$remotePath] = $content;

        return $this->writable;
    }

    /**
     * Serves the `files` map as the remote filesystem, so a download reads back what a test planted.
     */
    public function downloadToFile(string $remotePath, string $localPath): bool
    {
        if (! array_key_exists($remotePath, $this->files)) {
            return false;
        }

        file_put_contents($localPath, $this->files[$remotePath]);

        return true;
    }

    public function disconnect(): void
    {
    }

    /** The first command sent with this prefix, or '' when none was. */
    public function commandStartingWith(string $prefix): string
    {
        return array_find($this->commands, static fn (string $c): bool => str_starts_with($c, $prefix)) ?? '';
    }

    /**
     * @return list<string>
     */
    public function commandsStartingWith(string $prefix): array
    {
        return array_values(array_filter($this->commands, static fn (string $c): bool => str_starts_with($c, $prefix)));
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
