<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Payments\Actions\FlagUnconfirmedBillPaymentsAction;
use Throwable;

class FlagUnconfirmedBillPaymentsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'scribe:flag-unconfirmed-bill-payments
                            {--grace-days=10 : Days a recorded payment may take to show up in the bank}';

    protected $description = 'Flag bills marked paid whose payment never appeared in the bank.';

    public function handle(): int
    {
        $graceDays = max(1, (int) $this->option('grace-days'));

        $tuples = DB::connection('accounting')
            ->table('payments')
            ->where('is_deleted', false)
            ->whereNull('reconciled_at')
            ->whereNotNull('recorded_via')
            ->whereNull('unconfirmed_flagged_at')
            ->groupBy('apps_id', 'companies_id')
            ->select('apps_id', 'companies_id')
            ->get();

        $flagged = 0;

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

            try {
                $flagged += count(new FlagUnconfirmedBillPaymentsAction($app, $company, $graceDays)->execute());
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->info("Flagged {$flagged} unconfirmed payment(s).");

        return self::SUCCESS;
    }
}
