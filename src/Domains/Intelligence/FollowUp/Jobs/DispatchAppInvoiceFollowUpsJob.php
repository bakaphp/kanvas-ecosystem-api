<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * Fans the app's open SENT invoices out to InvoiceFollowUpJob. The SQL window only drops invoices
 * no default step can reach yet (due more than LOOKAHEAD_DAYS out); the action applies each company's
 * own schedule. Default queue: one job per open invoice per day is low volume, so no worker of its own.
 */
final class DispatchAppInvoiceFollowUpsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const int LOOKAHEAD_DAYS = 30;

    public function __construct(
        public readonly Apps $app,
    ) {
    }

    public function handle(): int
    {
        $this->overwriteAppService($this->app);
        $queued = 0;

        Invoice::query()
            ->where('apps_id', $this->app->getId())
            ->where('is_deleted', 0)
            ->where('document_status', InvoiceDocumentStatusEnum::SENT->value)
            ->where('balance_due_native', '>', 0)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', Carbon::today()->addDays(self::LOOKAHEAD_DAYS))
            ->orderBy('id')
            ->chunkById(200, function ($invoices) use (&$queued): void {
                foreach ($invoices as $invoice) {
                    InvoiceFollowUpJob::dispatch($this->app, $invoice);
                    $queued++;
                }
            });

        return $queued;
    }
}
