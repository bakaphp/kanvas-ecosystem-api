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
use Throwable;

/**
 * Per-app fan-out. SQL pre-filters at STAGE level (enabled + non-terminal) and to open leads (by
 * status id); the lead-level gates (ai follow-up, lead type, exhausted, manual, mute, and the stage
 * interval against the lead's last message) run here too, so a lead that was already followed up
 * never takes a queue slot. Every lead leaves a ledger record: `lead.follow_up.skipped` with the
 * reason and `source: dispatch`, or `lead.follow_up.queued`. FollowUpLeadAction still re-checks
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

        $eligibility = new LeadFollowUpEligibilityService();
        $leads = $eligibility->candidates($this->app, $this->company)->lazyById(500);

        foreach ($leads as $lead) {
            $skipReason = $eligibility->dispatchSkipReason($lead);
            if ($skipReason !== null) {
                $this->record($lead, 'lead.follow_up.skipped', ['reason' => $skipReason]);

                continue;
            }

            LeadFollowUpJob::dispatch($this->app, $this->company, $lead)
                ->onQueue('lead_follow_ups');
            $this->record($lead, 'lead.follow_up.queued');
        }
    }

    /**
     * A failed ledger write is reported, never allowed to stop the fan-out for the remaining leads.
     */
    private function record(Lead $lead, string $eventType, array $payload = []): void
    {
        try {
            $lead->emitLedgerEvent($eventType, payload: [
                'stage_id' => $lead->pipeline_stage_id,
                ...$payload,
                'source' => 'dispatch',
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
