<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Contracts;

use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\FactoryHealth;

/**
 * The machine a coding chat drives, as the chat needs it: real checkouts it opens and releases, the
 * server it can start, and the health it reports. Built by `HarnessFactory`; the domain never names the
 * connector that implements it.
 */
interface CodingFactory
{
    /**
     * `$name` and `$persona` replace the factory's own agent name and persona for this checkout.
     * `$worktreeBranch` is for a workflow task: its own worktree on that branch instead of the checkout.
     *
     * @return array{repo: string, directory: string, branch: string, port: int, password: string, provider: string, model: string}
     */
    public function open(
        string $repo,
        string $owner,
        ?string $name = null,
        ?string $persona = null,
        ?string $worktreeBranch = null
    ): array;

    /**
     * @return array<string, mixed>
     */
    public function release(string $repo, string $owner): array;

    /**
     * @return list<string>
     */
    public function repos(): array;

    /**
     * Starts the factory's server when it is down; a no-op when it already answers.
     */
    public function start(): void;

    /**
     * The machine as a whole: crashes are reported since `$since`, on the machine's own clock.
     */
    public function health(int $since): FactoryHealth;
}
