<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Actions;

use Kanvas\Connectors\OpenCode\Concerns\ClosesSessionsInContainer;
use Kanvas\Connectors\OpenCode\Concerns\ListsAgentCodingContainers;
use Kanvas\Connectors\OpenCode\Runtime\CodingRuntimeDriverFactory;
use Kanvas\Connectors\OpenCode\SshClient;
use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\CodingRuntimeDriver;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Removes the coding containers on a machine that are not running the configured image, so the next
 * task each agent runs starts on the new one.
 *
 * Nothing is started here. A container is created at dispatch with the agent's own key, and
 * `ContainerRuntimeDriver` already replaces one whose image no longer matches. This exists
 * so an upgrade does not wait for every agent to pick up a task, and so an operator can see what is
 * still on the old image and why.
 *
 * A container with a job running, or a push awaiting approval, is kept unless forced — the diff lives
 * in it. Forced, the sessions in it are closed with a reason rather than left saying "running".
 */
class ReplaceStaleCodingContainersAction
{
    use ClosesSessionsInContainer;
    use ListsAgentCodingContainers;

    public function __construct(
        private readonly AgentMachine $machine,
        private readonly string $image,
        private readonly bool $force = false,
        private readonly bool $dryRun = false,
    ) {
    }

    /**
     * @return array{replaced: list<string>, kept: list<string>, sessions_closed: int}
     */
    public function execute(): array
    {
        $result = ['replaced' => [], 'kept' => [], 'sessions_closed' => 0];

        // A machine no coding session has run on holds nothing of ours, and this is a query rather
        // than an SSH timeout.
        if (! AgentTaskSession::query()->onMachine($this->machine->getId(), HarnessEnum::OPENCODE)->exists()) {
            return $result;
        }

        $client = $this->openSshClient($this->machine);
        $driver = $this->driver();
        $current = $driver->versionFor($this->image);

        try {
            $stale = array_filter(
                $this->agentContainers($client, $this->machine),
                static fn (array $runtime): bool => $runtime['version'] !== $current
            );
            $busy = $this->busyAgentIds(array_keys($stale));

            foreach ($stale as $agentId => $runtime) {
                $inUse = in_array($agentId, $busy, true);

                if ($inUse && ! $this->force) {
                    $result['kept'][] = $runtime['handle'];

                    continue;
                }

                if ($this->dryRun) {
                    $result['replaced'][] = $runtime['handle'];

                    continue;
                }

                if (! $driver->remove($runtime['handle'], $client)) {
                    $result['kept'][] = $runtime['handle'];

                    continue;
                }

                $result['replaced'][] = $runtime['handle'];

                if ($inUse) {
                    $result['sessions_closed'] += $this->closeSessionsIn(
                        $runtime['handle'],
                        $agentId,
                        $this->machine->getId(),
                        'The coding runtime was replaced while this job was running: image upgraded to ' . $this->image
                    );
                }
            }
        } finally {
            $client->disconnect();
        }

        return $result;
    }

    protected function driver(): CodingRuntimeDriver
    {
        return CodingRuntimeDriverFactory::forMachine($this->machine);
    }

    protected function openSshClient(AgentMachine $machine): SshClient
    {
        return SshClient::fromMachine($machine);
    }
}
