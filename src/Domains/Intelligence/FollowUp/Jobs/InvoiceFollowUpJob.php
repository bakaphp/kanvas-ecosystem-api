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
use Kanvas\Intelligence\FollowUp\Actions\FollowUpInvoiceAction;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpFailureReasonEnum;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Setup\Services\AccountingAgentLocatorService;
use Throwable;

/**
 * Per-invoice wrapper. The AR agent is resolved at handle() so a hire between dispatch and run counts.
 */
final class InvoiceFollowUpJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly Apps $app,
        public readonly Invoice $invoice,
        public readonly bool $force = false,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        /** @var Companies $company */
        $company = $this->invoice->company;

        new FollowUpInvoiceAction(
            invoice: $this->invoice,
            agent: new AccountingAgentLocatorService()->receivables($this->app, $company),
            force: $this->force,
        )->execute();
    }

    public function failed(Throwable $e): void
    {
        try {
            $this->invoice->emitLedgerEvent('scribe.invoice.follow_up.failed', EventStatusEnum::ERROR, [
                'reason' => FollowUpFailureReasonEnum::JOB_FAILED->value,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);
        } catch (Throwable $ledgerError) {
            report($ledgerError);
        }
    }
}
