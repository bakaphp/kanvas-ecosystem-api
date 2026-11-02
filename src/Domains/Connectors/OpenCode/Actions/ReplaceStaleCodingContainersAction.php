<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Actions;

use Kanvas\Connectors\OpenCode\Concerns\ClosesSessionsInContainer;
use Kanvas\Connectors\OpenCode\Concerns\ListsAgentCodingContainers;
use Kanvas\Connectors\OpenCode\SshClient;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Removes the coding containers on a machine that are not running the configured image, so the next
 * task each agent runs starts on the new one.
 *
 * Nothing is started here. A container is created at dispatch with the agent's own key, and
 * `EnsureAgentCodingContainerAction` already replaces one whose image no longer matches. This exists
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

        try {
            $stale = array_filter(
                $this->agentContainers($client, $this->machine),
                fn (array $container): bool => $container['image'] !== $this->image
            );
            $busy = $this->busyAgentIds(array_keys($stale));

            foreach ($stale as $agentId => $container) {
                $inUse = in_array($agentId, $busy, true);

                if ($inUse && ! $this->force) {
                    $result['kept'][] = $container['name'];

                    continue;
                }

                $result['replaced'][] = $container['name'];

                if ($this->dryRun) {
                    continue;
                }

                $client->exec('docker rm -f ' . escapeshellarg($container['name']) . ' 2>&1 || true', 120);

                if ($inUse) {
                    $result['sessions_closed'] += $this->closeSessionsIn(
                        $container['name'],
                        $agentId,
                        'The coding runtime was replaced while this job was running: image upgraded to ' . $this->image
                    );
                }
            }
        } finally {
            $client->disconnect();
        }

        return $result;
    }

    protected function openSshClient(AgentMachine $machine): SshClient
    {
        return SshClient::fromMachine($machine);
    }
}
