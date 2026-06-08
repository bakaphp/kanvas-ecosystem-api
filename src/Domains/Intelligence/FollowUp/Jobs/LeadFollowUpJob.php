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
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\AgentEnum;
use Kanvas\Intelligence\FollowUp\Actions\FollowUpLeadAction;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpConfig;

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

    public function handle(): void
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
        )->execute();
    }

    private function resolveAgentName(): string
    {
        $config = FollowUpConfig::fromStage($this->lead->stage);

        return $config?->agentName ?? AgentEnum::FOLLOW_UP_ENGAGER->value;
    }
}
