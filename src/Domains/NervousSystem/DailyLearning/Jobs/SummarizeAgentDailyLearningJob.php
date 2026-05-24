<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\DailyLearning\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\DailyLearning\Actions\SummarizeAgentDailyLearningAction;
use Throwable;

/**
 * Fans the per-agent daily summarization out onto the agent-runtime queue.
 * Carbon is shipped as ISO-8601 to dodge any Spatie-Data-style serialization
 * surprises on Eloquent models nested in DTOs (we don't have one here, but
 * the discipline keeps job constructors trivially restorable).
 *
 * Retries cost a Gemini call so we don't retry on any LLM/SSH failure — let
 * the action's internal Log::warning paths capture it. Use `--tries=1`
 * implicitly via the framework default; do NOT increase retries casually.
 */
class SummarizeAgentDailyLearningJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        protected readonly Agent $agent,
        protected readonly Apps $app,
        protected readonly Companies $company,
        protected readonly string $cycleDateIso,
        protected readonly bool $dryRun = false,
        protected readonly bool $skipPush = false,
        protected readonly string $model = 'gemini-2.5-pro',
    ) {
        $this->onQueue('agent-runtime');
    }

    public function handle(): void
    {
        $cycleDate = Carbon::parse($this->cycleDateIso);

        try {
            new SummarizeAgentDailyLearningAction(
                $this->agent,
                $this->app,
                $this->company,
                $cycleDate,
                $this->dryRun,
                $this->skipPush,
                $this->model,
            )->execute();
        } catch (Throwable $e) {
            // Don't bounce the whole sweep on one agent's failure. The
            // action's persist/ledger paths already log; only surface the
            // catch-all here for cases that bubble past those.
            report($e);
        }
    }
}
