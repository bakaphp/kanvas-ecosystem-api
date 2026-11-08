<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\FiscalRegimes;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Users\Models\Users;

/**
 * Renders our own legal PDF of an accepted e-CF off the request that recorded the acceptance: a webhook or
 * a poll must not wait on wkhtmltopdf. Low volume, default queue.
 */
final class GenerateEcfPdfJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly Apps $app,
        public readonly Invoice $invoice,
        public readonly Users $user,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        FiscalRegimes::requireForCompany($this->invoice->company)->renderInvoicePdf($this->invoice->refresh(), $this->user);
    }
}
