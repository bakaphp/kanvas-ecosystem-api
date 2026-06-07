<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Services;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\SshClient;

/**
 * Runs the `hermes` CLI inside an agent container over an open SSH connection — any subcommand,
 * not just kanban. Transport: SSH → `docker exec -u hermes <container> <binary> <args…>`.
 *
 *  - MUST run as the `hermes` user: the gateway/dispatcher runs as `hermes`, and a root-run command
 *    poisons $HERMES_HOME with root-owned dirs that break the dispatcher.
 *  - The image is Python-based, so the binary is the venv entrypoint, not `node /app/hermes.mjs`.
 *  - Node/Python may print warnings before JSON, so the JSON decode strips any non-JSON preamble.
 */
final class HermesContainerCliService
{
    public const CONTAINER_USER = 'hermes';
    public const DEFAULT_BINARY = '/opt/hermes/.venv/bin/hermes';

    public function __construct(
        private readonly SshClient $ssh,
        private readonly string $containerName,
        private readonly string $binary = self::DEFAULT_BINARY,
    ) {
    }

    /**
     * @param list<string> $args
     */
    public function run(array $args, int $timeout = 120): string
    {
        $escaped = implode(' ', array_map(static fn (string $a): string => escapeshellarg($a), $args));

        $command = 'docker exec -u ' . self::CONTAINER_USER . ' '
            . escapeshellarg($this->containerName) . ' '
            . $this->binary . ' '
            . $escaped;

        return $this->ssh->exec($command, $timeout);
    }

    /**
     * @param list<string> $args
     * @return array<array-key, mixed>
     */
    public function runJson(array $args, int $timeout = 120): array
    {
        $args[] = '--json';

        return $this->decode($this->run($args, $timeout));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(string $raw): array
    {
        $trimmed = trim($raw);

        $starts = array_filter(
            [strpos($trimmed, '{'), strpos($trimmed, '[')],
            static fn (int|false $pos): bool => $pos !== false,
        );

        if ($starts === []) {
            throw new ValidationException('Hermes CLI returned non-JSON output: ' . $this->snippet($trimmed));
        }

        $decoded = json_decode(substr($trimmed, min($starts)), true);

        if (! is_array($decoded)) {
            throw new ValidationException('Hermes CLI returned malformed JSON: ' . $this->snippet($trimmed));
        }

        return $decoded;
    }

    private function snippet(string $raw): string
    {
        return mb_strlen($raw) > 200 ? mb_substr($raw, 0, 200) . '…' : $raw;
    }
}
