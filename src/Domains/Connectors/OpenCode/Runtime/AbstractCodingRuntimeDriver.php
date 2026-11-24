<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Runtime;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\CodingRuntimeDriver;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\RuntimeHandle;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Override;

/**
 * What the two runtimes share: the per-agent lock around `ensure()`, the cap, and the agent fields
 * the port and password are kept in.
 */
abstract class AbstractCodingRuntimeDriver implements CodingRuntimeDriver
{
    protected const int REMOVAL_ATTEMPTS = 15;

    public function __construct(
        protected readonly AgentMachine $machine,
    ) {
    }

    /**
     * Serialised per agent, because this is exactly what two tasks starting together do.
     *
     * Both find no runtime, both remove the name, and both start one — the loser gets "name is
     * already in use" against a runtime the winner created a fraction of a second earlier, and its
     * task fails for a reason that has nothing to do with the task. Whoever waits here finds a
     * running runtime and reuses it, which is the intended path anyway.
     */
    #[Override]
    public function ensure(Agent $agent, SshClient $client): RuntimeHandle
    {
        return Cache::lock('coding-container-agent-' . $agent->getId(), 300)
            ->block(120, fn (): RuntimeHandle => $this->ensureUnlocked($agent, $client));
    }

    abstract protected function ensureUnlocked(Agent $agent, SshClient $client): RuntimeHandle;

    protected function pollDelaySeconds(): int
    {
        return 1;
    }

    protected function removeOrFail(string $handle, SshClient $client): void
    {
        if (! $this->remove($handle, $client)) {
            throw new ValidationException(
                'The previous runtime ' . $handle . ' on ' . $this->machine->name
                . ' could not be removed, so a new one cannot take its name.'
            );
        }
    }

    /**
     * `max_agents` counts the box's own listing, stopped runtimes included, not the sessions table: a
     * runtime owns its name and its disk whether or not a session row remembers it. An agent that
     * already has one on the box is never refused — rolling its own stale runtime over adds nothing,
     * and a full machine is exactly where an upgrade must still land.
     */
    protected function assertCapacityFor(Agent $agent, SshClient $client): void
    {
        $listed = $this->list($client);
        $own = array_filter($listed, static fn (array $runtime): bool => $runtime['agentId'] === $agent->getId());

        if ($own !== [] || count($listed) < $this->machine->max_agents) {
            return;
        }

        throw new ValidationException(
            'Machine ' . $this->machine->name . ' is at its ' . $this->machine->max_agents
            . '-agent cap; no runtime for agent ' . $agent->name . ' can start there.'
        );
    }

    protected function storedPort(Agent $agent): ?int
    {
        $port = Str::trimToNull((string) $agent->get(AgentCustomFieldEnum::CONTAINER_PORT->value));

        return $port === null ? null : (int) $port;
    }

    protected function storedPassword(Agent $agent): string
    {
        return (string) $agent->get(AgentCustomFieldEnum::CONTAINER_PASSWORD->value);
    }

    protected function installHint(AppInterface $app): string
    {
        return ' — run: php artisan kanvas:coding:build-image --app=' . $app->getId()
            . ' --machine=' . $this->machine->getId() . '.';
    }
}
