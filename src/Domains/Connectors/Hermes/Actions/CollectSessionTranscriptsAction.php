<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Hermes\Services\HermesJsonlFallbackReaderService;
use Kanvas\Connectors\Hermes\Services\HermesStateDbReaderService;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseCollectSessionTranscriptsAction;
use Kanvas\Intelligence\AgentRuntime\Contracts\SessionTranscriptReader;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Override;
use RuntimeException;
use Throwable;

/**
 * Owns the SSH connection lifecycle and the state.db → jsonl reader
 * fallback. Persistence + idempotency live in the base action.
 */
class CollectSessionTranscriptsAction extends BaseCollectSessionTranscriptsAction
{
    private ?SshClient $sshClient = null;

    private ?bool $usedFallback = null;

    #[Override]
    protected function reader(): SessionTranscriptReader
    {
        $ssh = $this->ssh();

        try {
            $reader = new HermesStateDbReaderService($ssh);
            // Probe up front so missing-sqlite3 / unreachable-db surfaces here,
            // not in the middle of the base action's persist loop.
            $this->probeStateDb($ssh);
            $this->usedFallback = false;
            return $reader;
        } catch (Throwable $e) {
            $this->reportStateDbUnavailable($e);
            $this->usedFallback = true;
            return new HermesJsonlFallbackReaderService($ssh);
        }
    }

    #[Override]
    public function execute(): int
    {
        try {
            return parent::execute();
        } finally {
            $this->sshClient?->disconnect();
            $this->sshClient = null;
        }
    }

    #[Override]
    protected function runtimeName(): string
    {
        return 'hermes';
    }

    public function usedJsonlFallback(): ?bool
    {
        return $this->usedFallback;
    }

    private function ssh(): SshClient
    {
        if ($this->sshClient instanceof SshClient) {
            return $this->sshClient;
        }
        $this->sshClient = SshClient::fromMachine($this->deployment->machine);
        return $this->sshClient;
    }

    /**
     * Notifies operators that this AgentMachine has no usable state.db read
     * path — typically `sqlite3` not installed on the host, occasionally
     * permissions or a missing file. Two channels:
     *  - Sentry-grade Log::warning so it lands in the existing log pipe.
     *  - Ledger event 'hermes.state_db.unavailable' (status ERROR → routes
     *    to the WARNING Pulse category via Event::categoryFor) so dashboards
     *    surface it without us authoring a new query.
     * Throttled per-host for 6h so 50 agents on one broken box don't flood
     * Pulse with redundant emissions.
     */
    private function reportStateDbUnavailable(Throwable $error): void
    {
        $machine = $this->deployment->machine;
        $host = $machine->host ?? 'unknown';

        Log::warning('Hermes state.db reader unavailable — install sqlite3 on host', [
            'deployment_id' => $this->deployment->getId(),
            'machine_host' => $host,
            'machine_id' => $machine?->getId(),
            'error' => $error->getMessage(),
            'action_required' => 'apt install sqlite3 on the EC2 host',
        ]);

        $cacheKey = 'hermes.state_db_warn.' . md5((string) $host);
        if (! Cache::add($cacheKey, true, now()->addHours(6))) {
            return;
        }

        new AppendEventAction(new EventData(
            app: $this->app,
            company: $this->company,
            sourceDomain: 'Connectors.Hermes',
            eventType: 'hermes.state_db.unavailable',
            status: EventStatusEnum::ERROR,
            sourceEntityType: 'AgentDeployment',
            sourceEntityId: $this->deployment->getId(),
            actorType: 'System',
            payload: [
                'machine_host' => $host,
                'machine_id' => $machine?->getId(),
                'deployment_id' => $this->deployment->getId(),
                'agent_id' => $this->deployment->agent?->getId(),
                'fallback' => 'jsonl',
                'error' => $error->getMessage(),
                'action_required' => 'install sqlite3 on the EC2 host',
            ],
        ))->execute();
    }

    /**
     * Cheapest sanity check — confirms sqlite3 is on the EC2 host's PATH
     * AND the bind-mounted db is readable. Reading happens host-side, not
     * inside the container (SQLite is just a file; the host has the same
     * view of state.db / .db-shm / .db-wal via the agent home bind mount).
     *
     * Dependency: the EC2 host must have `sqlite3` installed (Ansible /
     * cloud-init). Container does not need it.
     */
    private function probeStateDb(SshClient $ssh): void
    {
        $home = $this->deployment->home_directory !== ''
            ? $this->deployment->home_directory
            : '/home/' . $this->deployment->system_user;
        $dbPath = rtrim($home, '/') . '/.hermes/state.db';

        $output = $ssh->exec(
            'sudo -n sqlite3 -readonly -bail ' . escapeshellarg($dbPath) . ' "SELECT 1" 2>&1',
            15,
        );
        $trimmed = trim($output);
        if ($trimmed !== '1') {
            throw new RuntimeException('Hermes state.db probe returned unexpected output: ' . $trimmed);
        }
    }
}
