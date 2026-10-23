<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\CrmLeadSourceSynchronizer;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\AgentEnum;
use Kanvas\Intelligence\FollowUp\Actions\FollowUpLeadAction;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpConfig;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpFailureReasonEnum;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Throwable;

/**
 * Per-lead wrapper. Resolves agent at handle() so config changes between
 * dispatch and execution are picked up.
 *
 * Eloquent models go directly on the constructor — NEVER nest them inside a
 * Spatie Data DTO on a queued job (typed-property fatal on unserialize, see
 * memory: feedback_no_spatie_data_dto_in_queued_jobs.md).
 */
final class LeadFollowUpJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly Lead $lead,
        public readonly bool $force = false,
    ) {
    }

    /**
     * The synchronizer is method-injected so tests can hand in a fake; the action decides when to
     * use it (only after every free gate, since CRM calls are billed).
     */
    public function handle(CrmLeadSourceSynchronizer $synchronizer): void
    {
        $this->overwriteAppService($this->app);

        $agentName = $this->resolveAgentName();

        /** @var Agent $agent */
        $agent = Agent::getByNameFromCompanyApp(
            $agentName,
            $this->company,
            $this->app
        );

        new FollowUpLeadAction(
            app: $this->app,
            company: $this->company,
            lead: $this->lead,
            agent: $agent,
            force: $this->force,
            synchronizer: $synchronizer,
        )->execute();
    }

    /**
     * Runs once the last try is spent. Without it a crashed run leaves only `queued` in the ledger,
     * and the follow-up reports could not tell it from one still waiting.
     */
    public function failed(Throwable $e): void
    {
        try {
            $this->lead->emitLedgerEvent('lead.follow_up.failed', EventStatusEnum::ERROR, [
                'stage_id' => $this->lead->pipeline_stage_id,
                'reason' => FollowUpFailureReasonEnum::JOB_FAILED->value,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);
        } catch (Throwable $ledgerError) {
            report($ledgerError);
        }
    }

    private function resolveAgentName(): string
    {
        $config = FollowUpConfig::fromStage($this->lead->stage);

        return $config?->agentName ?? AgentEnum::FOLLOW_UP_ENGAGER->value;
    }
}
