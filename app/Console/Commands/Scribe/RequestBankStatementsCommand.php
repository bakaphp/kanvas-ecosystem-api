<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\Banking\Actions\RequestBankStatementAction;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Services\BankStatementCoverageService;
use Throwable;

/**
 * Three times a month (10th, 20th, month-end — ScribeSchedule) every statement-driven bank account whose
 * coverage is behind gets its owner asked for the missing movements. Accounts fed by a bank API are
 * skipped: nobody has a statement to send for them.
 */
class RequestBankStatementsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'scribe:request-bank-statements';

    protected $description = 'Ask account owners for bank statements where statement coverage is behind.';

    public function handle(): int
    {
        $coverage = new BankStatementCoverageService();
        $requested = 0;

        $accounts = BankAccount::query()
            ->where('is_deleted', false)
            ->where('is_active', true)
            ->orderBy('apps_id')
            ->get();

        foreach ($accounts as $account) {
            $app = Apps::find($account->apps_id);
            if ($app === null) {
                continue;
            }

            $this->overwriteAppService($app);

            if (! $coverage->isStatementDriven($account) || ! $coverage->isBehind($account)) {
                continue;
            }

            try {
                if (new RequestBankStatementAction($account, $coverage)->execute() !== null) {
                    $requested++;
                }
            } catch (Throwable $e) {
                report($e);
                $this->warn("Bank account {$account->getId()}: {$e->getMessage()}");
            }
        }

        $this->info("Requested statements for {$requested} bank account(s).");

        return self::SUCCESS;
    }
}
