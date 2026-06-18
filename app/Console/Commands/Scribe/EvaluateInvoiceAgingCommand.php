<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Invoices\Jobs\EvaluateInvoiceAgingJob;

/**
 * Daily fan-out — dispatches one EvaluateInvoiceAgingJob per (app, company) tuple
 * that has at least one open Scribe invoice.
 *
 * Scoping: skips tuples with no open AR — avoids burning queue slots on tenants that
 * haven't issued an invoice this period.
 *
 * Per the memory rule, calls overwriteAppService($app) PER ITERATION before resolving the
 * Companies model, so Bouncer scope doesn't leak across tenants.
 */
class EvaluateInvoiceAgingCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'scribe:evaluate-invoice-aging
                            {--today= : ISO date to evaluate as (defaults to today)}';

    protected $description = 'Dispatch EvaluateInvoiceAgingJob for every (app, company) tuple with open AR.';

    public function handle(): int
    {
        $todayIso = $this->option('today') ?: Carbon::today()->toDateString();

        $tuples = DB::connection('accounting')
            ->table('invoices')
            ->where('is_deleted', false)
            ->where('balance_due_base', '>', 0.005)
            ->whereIn('document_status', ['issued', 'sent'])
            ->groupBy('apps_id', 'companies_id')
            ->select('apps_id', 'companies_id')
            ->get();

        $this->info("Evaluating aging for {$tuples->count()} (app, company) tuples — today={$todayIso}");

        $dispatched = 0;
        foreach ($tuples as $tuple) {
            $app = Apps::find((int) $tuple->apps_id);
            if ($app === null) {
                continue;
            }

            $this->overwriteAppService($app);

            $company = Companies::find((int) $tuple->companies_id);
            if ($company === null) {
                continue;
            }

            EvaluateInvoiceAgingJob::dispatch(
                app: $app,
                company: $company,
                todayIso: $todayIso,
            );
            $dispatched += 1;
        }

        $this->info("Dispatched {$dispatched} aging jobs.");

        return self::SUCCESS;
    }
}
