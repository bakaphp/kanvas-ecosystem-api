<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Services;

use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\CodingFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\FactoryHealth;
use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\CheckoutInUseException;
use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\HarnessTransportException;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Override;
use Throwable;

/**
 * `scripts/serve.sh` in the opencode factory on a machine: the factory prepares the checkout (branch,
 * baseline, persona, rules, command lockdown) exactly as `akira` does, and answers with one JSON line.
 * Kanvas never writes the session config itself here — the factory is the single source of truth.
 */
class FactoryServeClient implements CodingFactory
{
    /** `open` measures the repository's baseline (tsc, lint, tests) before it answers. */
    private const int OPEN_TIMEOUT_SECONDS = 900;

    private const int RELEASE_TIMEOUT_SECONDS = 60;

    /** `start` over SSH hands the start to the Mac's desktop login and waits up to a minute for it. */
    private const int START_TIMEOUT_SECONDS = 120;

    public function __construct(
        private readonly AgentMachine $machine,
        private readonly string $factoryDir,
    ) {
    }

    /**
     * `$name` and `$persona` replace the factory's own agent name and "Who you are" section for this
     * checkout. They travel base64-encoded: free text from a tenant, one argument each.
     *
     * `$worktreeBranch` is for a workflow task: it works in its own worktree on that branch, cut from the
     * app's base branch, instead of the shared checkout a chat holds the lock on.
     *
     * @return array{repo: string, directory: string, branch: string, port: int, password: string, provider: string, model: string}
     */
    #[Override]
    public function open(
        string $repo,
        string $owner,
        ?string $name = null,
        ?string $persona = null,
        ?string $worktreeBranch = null
    ): array {
        $args = ['open', $repo, $owner];

        if ($worktreeBranch !== null) {
            array_push($args, '--worktree', $worktreeBranch);
        }

        if ($name !== null) {
            array_push($args, '--name', base64_encode($name));
        }

        if ($persona !== null) {
            array_push($args, '--persona', base64_encode($persona));
        }

        $result = $this->call($args, self::OPEN_TIMEOUT_SECONDS);

        foreach (['directory', 'branch', 'port', 'password', 'provider', 'model'] as $key) {
            if (! is_string($result[$key] ?? null) || $result[$key] === '') {
                throw new HarnessTransportException('The factory did not report a ' . $key . ' for ' . $repo . '.');
            }
        }

        /** @var array<string, string> $result */
        return [
            'repo' => $repo,
            'directory' => $result['directory'],
            'branch' => $result['branch'],
            'port' => (int) $result['port'],
            'password' => $result['password'],
            'provider' => $result['provider'],
            'model' => $result['model'],
        ];
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function repos(): array
    {
        $listed = (string) ($this->call(['list'], self::RELEASE_TIMEOUT_SECONDS)['repos'] ?? '');

        return array_values(array_filter(explode(',', $listed), static fn (string $repo): bool => $repo !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function release(string $repo, string $owner): array
    {
        return $this->call(['release', $repo, $owner], self::RELEASE_TIMEOUT_SECONDS);
    }

    /**
     * Starts the factory's opencode server when it is down; a no-op when it already answers.
     */
    #[Override]
    public function start(): void
    {
        $result = $this->call(['start'], self::START_TIMEOUT_SECONDS);

        if (($result['running'] ?? null) !== 'true') {
            throw new HarnessTransportException('The factory server on ' . $this->machine->name . ' did not start.');
        }
    }

    /**
     * The Mac as a whole, not one checkout: crashes are reported since `$since`, on the Mac's own clock.
     */
    #[Override]
    public function health(int $since): FactoryHealth
    {
        return FactoryHealth::from($this->call(['health', (string) $since], self::RELEASE_TIMEOUT_SECONDS));
    }

    /**
     * @param list<string> $args
     * @return array<string, mixed>
     */
    private function call(array $args, int $timeout): array
    {
        $command = 'cd && ' . escapeshellarg(rtrim($this->factoryDir, '/') . '/scripts/serve.sh') . ' '
            . implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null';

        try {
            $output = $this->run($command, $timeout);
        } catch (Throwable $e) {
            throw new HarnessTransportException('Could not reach the factory on ' . $this->machine->name . ': ' . $e->getMessage());
        }

        $result = $this->lastJsonLine($output);

        if ($result === null) {
            throw new HarnessTransportException(
                'The factory on ' . $this->machine->name . ' gave no answer: ' . mb_substr(trim($output), 0, 300)
            );
        }

        if (isset($result['error'], $result['holder'])) {
            throw new CheckoutInUseException((string) $result['error'], (string) $result['holder']);
        }

        if (isset($result['error'])) {
            throw new HarnessTransportException((string) $result['error']);
        }

        return $result;
    }

    protected function run(string $command, int $timeout): string
    {
        $client = $this->machine->connectSsh();

        try {
            return $client->exec($command, $timeout);
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastJsonLine(string $output): ?array
    {
        $lines = array_reverse(preg_split('/\R/', trim($output)) ?: []);

        foreach ($lines as $line) {
            $decoded = json_decode(trim($line), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
