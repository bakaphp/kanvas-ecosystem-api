<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Summary\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Summary\Actions\UpdateLeadSummaryAction;
use Kanvas\Guild\Leads\Summary\LeadSummaryRepository;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\AgentProviderService;
use NeuronAI\Providers\AIProviderInterface;

/**
 * Finishes catch-up a turn started and could not finish in its budget. Only ever queued by a turn
 * (token pressure or a coverage gap), never by time passing; coalesced per lead, and it stops at its
 * target, so a quiet lead costs nothing. Unique until processing, so a turn during a run can still
 * queue the next one and the run can queue its own continuation.
 */
class ContinueLeadSummaryJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    private const int CHUNKS_PER_RUN = 5;

    private const int SECONDS_PER_CHUNK = 60;

    public const int MAX_RUNS = 20;

    public int $tries = 3;
    public int $timeout = 400;
    public int $uniqueFor = 600;

    // Defaults also apply to jobs serialized before continuation limits were introduced.
    public int $continuations = 0;
    public int $previousCoveredThrough = 0;

    public function __construct(
        public readonly Lead $lead,
        public readonly Agent $agent,
        public readonly int $target
    ) {
    }

    public function uniqueId(): string
    {
        return (string) $this->lead->getId();
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->lead->app);

        $coveredThrough = LeadSummaryRepository::state($this->lead)->coveredThrough();

        if ($coveredThrough >= $this->target) {
            return;
        }

        if ($this->continuations >= self::MAX_RUNS) {
            $this->logStopped('chain_limit', $coveredThrough);

            return;
        }

        $coveredThrough = max($coveredThrough, $this->previousCoveredThrough);
        $provider = $this->summaryProvider();

        for ($chunk = 0; $chunk < self::CHUNKS_PER_RUN; $chunk++) {
            $progress = new UpdateLeadSummaryAction(
                $this->lead,
                $provider,
                microtime(true) + self::SECONDS_PER_CHUNK
            )->execute();

            if ($progress->state->coveredThrough() >= $this->target || ! $progress->saved) {
                if ($progress->failure !== null) {
                    $this->logStopped($progress->failure, $progress->state->coveredThrough());

                    // A provider or validation failure is worth a retry with backoff; the target stands.
                    $this->release(120 * $this->attempts());
                }

                return;
            }

            if ($progress->state->coveredThrough() <= $coveredThrough) {
                $this->logStopped('no_progress', $progress->state->coveredThrough());

                return;
            }

            $coveredThrough = $progress->state->coveredThrough();
        }

        if ($this->continuations + 1 >= self::MAX_RUNS) {
            $this->logStopped('chain_limit', $coveredThrough);

            return;
        }

        $next = new self($this->lead, $this->agent, $this->target);
        $next->continuations = $this->continuations + 1;
        $next->previousCoveredThrough = $coveredThrough;
        dispatch($next)->delay(5);
    }

    protected function summaryProvider(): AIProviderInterface
    {
        return AgentProviderService::summaryProviderOrOwn($this->agent);
    }

    private function logStopped(string $reason, int $coveredThrough): void
    {
        Log::warning('Lead summary continuation stopped', [
            'lead_id' => $this->lead->getId(),
            'target' => $this->target,
            'covered_through' => $coveredThrough,
            'continuations' => $this->continuations,
            'reason' => $reason,
        ]);
    }
}
