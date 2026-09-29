<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Reporting\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Analytics\Reporting\Contracts\RefreshableReportInterface;
use Kanvas\Analytics\Reporting\Services\ReportRefreshService;
use Kanvas\Analytics\Reporting\Support\ReportRegistry;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;

/**
 * Refresh a bounded set of report rows off the request.
 *
 * Fan-out is why this is queued rather than inline. A person's row depends on data that is not
 * the person: renaming an organization invalidates every one of its people, changing an event
 * version's dates invalidates every registration for it. Doing that synchronously would make
 * renaming a 500-person company block the request that renamed it.
 *
 * Primitives on the constructor, not a Spatie DTO — a Data object holding Apps/Companies does not
 * survive the queue's serialize/unserialize round trip.
 */
class RefreshReportRowsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<int, int> $ids primary-key values whose rows are stale
     */
    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly string $model,
        public readonly array $ids,
    ) {
        $this->onQueue('reporting');
    }

    public function handle(): void
    {
        // Bouncer scope and the container-bound app leak between jobs on a long-running worker.
        $this->overwriteAppService($this->app);

        if ($this->ids === []) {
            return;
        }

        $definition = new ReportRegistry()->find($this->app, $this->model);

        if (! $definition instanceof RefreshableReportInterface) {
            return;
        }

        new ReportRefreshService()->refresh($definition, $this->app, $this->company, $this->ids);
    }
}
