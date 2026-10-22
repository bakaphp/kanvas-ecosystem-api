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
use Kanvas\Intelligence\FollowUp\Services\LeadFollowUpEligibilityService;
use Kanvas\Intelligence\Services\LeadConfigurationService;

/**
 * Per-app fan-out. SQL pre-filters at STAGE level (enabled + non-terminal) and to open leads (by
 * status id); the lead-level gates (ai follow-up, lead type, exhausted, manual, mute, and the stage
 * interval against the lead's last message) run here too, so a lead that was already followed up
 * never takes a queue slot. Those skips leave no ledger event; FollowUpLeadAction still re-checks
 * every gate when the job runs.
 */
final class DispatchAppLeadFollowUpsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $startDate = new LeadConfigurationService()->getFollowUpStartDate($this->company);

        $leads = Lead::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            // Open leads only, by `leads_status_id` — the column CRM pulls and the UI write. The
            // authoritative check is FollowUpLeadAction's hasOpenLeadStatus() gate; this just
            // avoids spawning jobs for already-closed leads.
            ->hasOpenLeadStatus($this->company)
            ->when($startDate, fn ($query) => $query->where('created_at', '>=', $startDate))
            ->whereNotNull('pipeline_stage_id')
            ->whereHas(
                'stage',
                fn ($q) => $q
                    ->where('is_deleted', 0)
                    ->whereJsonContains('config->follow_up->enabled', true)
                    ->where(
                        fn ($inner) => $inner
                            ->whereJsonDoesntContain('config->stage_meta->is_terminal', true)
                            ->orWhereNull('config->stage_meta->is_terminal'),
                    ),
            )
            ->with('stage')
            ->lazyById(500);

        $eligibility = new LeadFollowUpEligibilityService();
        foreach ($leads as $lead) {
            if ($eligibility->dispatchSkipReason($lead) !== null) {
                continue;
            }

            LeadFollowUpJob::dispatch($this->app, $this->company, $lead)
                ->onQueue('lead_follow_ups');
        }
    }
}
