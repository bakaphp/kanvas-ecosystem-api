<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Hermes\Services\HermesMemoryBlockBuilderService;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\DailyLearningSummary;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use RuntimeException;
use Throwable;

/**
 * Closes the daily-learning loop on the Hermes side: appends the LLM-distilled
 * durable_facts to the agent's MEMORY.md so it lands in the agent's next prompt.
 * Read → dedup-and-append via HermesMemoryBlockBuilderService → write back.
 *
 * Returns false (not throws) on any failure — Kanvas-side persistence has
 * already happened; the memory push is best-effort feedback.
 */
class PushDailyLearningContextAction
{
    public function __construct(
        protected readonly AgentDeployment $deployment,
        protected readonly DailyLearningSummary $summary,
        protected readonly Carbon $cycleDate,
    ) {
    }

    public function execute(): bool
    {
        if ($this->summary->durable_facts === []) {
            // Nothing durable to push — narrative briefing alone doesn't
            // belong in MEMORY.md.
            return false;
        }

        $machine = $this->deployment->machine;
        if ($machine === null) {
            Log::warning('Hermes daily-learning push skipped: deployment has no machine', [
                'deployment_id' => $this->deployment->getId(),
            ]);
            return false;
        }

        $ssh = SshClient::fromMachine($machine);

        try {
            $path = $this->resolveMemoryPath();
            $existing = $this->safeReadFile($ssh, $path);

            $built = new HermesMemoryBlockBuilderService()->build($existing, $this->summary);

            if ($built['added'] === 0) {
                Log::info('Hermes daily-learning push: no new facts to add (all deduped)', [
                    'deployment_id' => $this->deployment->getId(),
                    'cycle_date' => $this->cycleDate->toDateString(),
                ]);
                return false;
            }

            $ssh->writeFileAsUser($path, $built['content'], $this->deployment->system_user);

            Log::info('Hermes daily-learning push: appended facts to MEMORY.md', [
                'deployment_id' => $this->deployment->getId(),
                'cycle_date' => $this->cycleDate->toDateString(),
                'added' => $built['added'],
                'evicted' => $built['evicted'],
            ]);

            return true;
        } catch (Throwable $e) {
            Log::error('Hermes daily-learning push failed', [
                'deployment_id' => $this->deployment->getId(),
                'cycle_date' => $this->cycleDate->toDateString(),
                'error' => $e->getMessage(),
            ]);
            return false;
        } finally {
            $ssh->disconnect();
        }
    }

    private function resolveMemoryPath(): string
    {
        $home = $this->deployment->home_directory !== ''
            ? $this->deployment->home_directory
            : '/home/' . $this->deployment->system_user;

        return rtrim($home, '/') . '/.hermes/memories/MEMORY.md';
    }

    /**
     * Read MEMORY.md if it exists; return empty string for first-push case
     * (agent has never had us write before). Other SSH errors propagate.
     */
    private function safeReadFile(SshClient $ssh, string $path): string
    {
        try {
            return $ssh->readFile($path);
        } catch (RuntimeException) {
            return '';
        }
    }
}
