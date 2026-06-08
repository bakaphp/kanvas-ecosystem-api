<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Kanban\Actions;

use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;

/**
 * Make the container's kanban dir writable by the gateway worker before we push a card.
 *
 * The dispatcher runs workers as the `hermes` user; if a prior root-run command left
 * `$HERMES_HOME/kanban` root-owned, worker spawns fail with "Permission denied: …/workspaces/<id>".
 * We check the dir mode and, only when it isn't already 777, `chmod -R 777` it (as root via
 * docker exec). No-op when already open — cheap to run on every push. (777 is an explicit ops
 * choice over chown; see the sync plan §6.2.)
 */
class EnsureKanbanWritableAction
{
    private const string KANBAN_DIR = '/opt/data/kanban';

    public function __construct(private readonly AgentDeployment $deployment)
    {
    }

    public function execute(): void
    {
        $machine = $this->deployment->machine;

        if (! $machine instanceof AgentMachine) {
            return;
        }

        $ssh = $this->openSshClient($machine);

        try {
            $container = escapeshellarg($this->deployment->container_name);

            $mode = trim($ssh->exec(
                'docker exec -u root ' . $container . ' sh -c '
                . escapeshellarg('stat -c %a ' . self::KANBAN_DIR . ' 2>/dev/null || echo missing'),
            ));

            if ($mode === '777') {
                return;
            }

            $ssh->exec(
                'docker exec -u root ' . $container . ' sh -c '
                . escapeshellarg('mkdir -p ' . self::KANBAN_DIR . ' && chmod -R 777 ' . self::KANBAN_DIR),
                60,
            );
        } catch (Throwable $e) {
            report($e);
        } finally {
            $ssh->disconnect();
        }
    }

    protected function openSshClient(AgentMachine $machine): BaseSshClient
    {
        return SshClient::fromMachine($machine);
    }
}
