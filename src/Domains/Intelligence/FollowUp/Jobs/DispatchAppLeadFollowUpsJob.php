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

/**
 * Per-app fan-out. SQL pre-filters at STAGE level (enabled + non-terminal)
 * and to open leads (status < 2); remaining lead-level state is checked inside
 * FollowUpLeadAction so each skip lands a clean ledger event with the specific
 * reason. Streams via cursor().
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

        $leads = Lead::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            // Open leads only (isOpen() === status < 2). null is treated as open
            // to match the model (status column is nullable, default 0). The
            // authoritative check is FollowUpLeadAction's isOpen() gate; this just
            // avoids spawning jobs for already-closed leads.
            ->where(
                fn ($q) => $q
                    ->whereNull('status')
                    ->orWhere('status', '<', 2),
            )
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
            ->cursor();

        foreach ($leads as $lead) {
            LeadFollowUpJob::dispatch($this->app, $this->company, $lead)
                ->onQueue('lead_follow_ups');
        }
    }
}
