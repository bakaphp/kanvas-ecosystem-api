<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Invoices\Services\AgingEvaluationService;

/**
 * Walks every open invoice for the given (app, company) and refreshes collection_state based on aging.
 *
 * Runs daily via scheduler — fan-out one job per (apps_id, companies_id) tuple so each tenant gets its
 * own queue slot and a failure on one tenant doesn't block the others.
 *
 * Per the memory rule, calls overwriteAppService() FIRST in handle() — without this, Bouncer scope from
 * the previous job leaks across iterations.
 */
final class EvaluateInvoiceAgingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly ?string $todayIso = null,
    ) {
        $this->onQueue('scribe-aging');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $today = $this->todayIso !== null ? Carbon::parse($this->todayIso) : Carbon::today();

        new AgingEvaluationService()->evaluate(
            app: $this->app,
            company: $this->company,
            today: $today,
        );
    }
}
