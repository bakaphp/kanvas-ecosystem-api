<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\FollowUp\Jobs\DispatchAppInvoiceFollowUpsJob;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;

class DispatchInvoiceFollowUpsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'scribe:dispatch-invoice-follow-ups {--app_id= : One app; omitted = every app with sent invoices}';

    protected $description = 'Queue the dunning reminder check for every sent, unpaid invoice.';

    public function handle(): int
    {
        $appIds = $this->option('app_id') !== null
            ? [(int) $this->option('app_id')]
            : Invoice::query()
                ->where('document_status', InvoiceDocumentStatusEnum::SENT->value)
                ->where('is_deleted', 0)
                ->distinct()
                ->pluck('apps_id')
                ->all();

        foreach ($appIds as $appId) {
            $app = Apps::find((int) $appId);
            if ($app === null) {
                continue;
            }

            $this->overwriteAppService($app);
            DispatchAppInvoiceFollowUpsJob::dispatch($app);
        }

        return self::SUCCESS;
    }
}
