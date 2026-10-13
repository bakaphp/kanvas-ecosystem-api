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
use Kanvas\Intelligence\FollowUp\Actions\SyncLeadSourceBeforeFollowUpAction;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpConfig;
use Kanvas\Intelligence\FollowUp\Enums\LeadSourceSyncOutcomeEnum;

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
     * Method-injected so tests can hand in a fake. Third-party leads are pulled first: a CRM
     * without webhooks never told us the lead was closed or sold, and a failed pull skips this
     * tick rather than nudge an unverified lead.
     */
    public function handle(CrmLeadSourceSynchronizer $synchronizer): void
    {
        $this->overwriteAppService($this->app);

        $sync = new SyncLeadSourceBeforeFollowUpAction($this->lead, $synchronizer)->execute();
        if ($sync === LeadSourceSyncOutcomeEnum::FAILED) {
            $this->lead->emitLedgerEvent('lead.follow_up.skipped', payload: [
                'stage_id' => $this->lead->pipeline_stage_id,
                'reason' => 'crm_sync_failed',
            ]);

            return;
        }

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
        )->execute();
    }

    private function resolveAgentName(): string
    {
        $config = FollowUpConfig::fromStage($this->lead->stage);

        return $config?->agentName ?? AgentEnum::FOLLOW_UP_ENGAGER->value;
    }
}
