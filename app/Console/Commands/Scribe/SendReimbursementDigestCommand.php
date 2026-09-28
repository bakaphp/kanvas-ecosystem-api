<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Expenses\Actions\SendReimbursementDigestAction;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseReimbursementStatusEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Models\Expense;
use Throwable;

/**
 * Monthly fan-out of the "what the company owes you" digest, one email per employee still owed.
 *
 * Discovers its tenant set from the outstanding expenses themselves rather than from a company list,
 * so a tenant with nothing outstanding costs one row in the discovery query and no work at all.
 */
class SendReimbursementDigestCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:scribe:send-reimbursement-digest
        {--app= : Restrict to a single apps_id}
        {--company= : Restrict to a single companies_id}
        {--as-of= : ISO date (Y-m-d) to report as of. Defaults to today.}';

    protected $description = 'Email each employee what the company still owes them in unreimbursed expenses.';

    public function handle(): int
    {
        $asOf = $this->option('as-of') !== null
            ? Carbon::parse((string) $this->option('as-of'))
            : Carbon::today();

        $tuples = $this->outstandingTenants($asOf);

        if ($tuples->isEmpty()) {
            $this->info('No outstanding employee reimbursements — nothing to send.');

            return self::SUCCESS;
        }

        $sentTotal = 0;
        $skippedTotal = 0;
        $failed = 0;

        foreach ($tuples as $row) {
            $appId = (int) $row->apps_id;
            $companyId = (int) $row->companies_id;

            try {
                /** @var Apps $app */
                $app = Apps::getById($appId);
                // Rebind per tuple, not once: the worker process is long-lived and Bouncer's scope
                // plus the container-bound Apps leak from whatever ran last.
                $this->overwriteAppService($app);
                /** @var Companies $company */
                $company = Companies::getById($companyId);
            } catch (Throwable $e) {
                $this->warn(sprintf(
                    'apps_id=%d companies_id=%d → tenant resolution failed: %s',
                    $appId,
                    $companyId,
                    $e->getMessage(),
                ));
                $failed++;

                continue;
            }

            try {
                $result = new SendReimbursementDigestAction($app, $company, $asOf)->execute();
                $sentTotal += $result['sent'];
                $skippedTotal += $result['skipped'];

                $this->line(sprintf(
                    '  app=%-3d company=%-4d sent=%d skipped=%d',
                    $appId,
                    $companyId,
                    $result['sent'],
                    $result['skipped'],
                ));
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error(sprintf('  app=%d company=%d failed: %s', $appId, $companyId, $e->getMessage()));
            }
        }

        $this->info(sprintf(
            'Reimbursement digest as of %s: %d sent, %d unattributed, %d failed.',
            $asOf->toDateString(),
            $sentTotal,
            $skippedTotal,
            $failed,
        ));

        if ($skippedTotal > 0) {
            $this->warn(sprintf(
                '%d outstanding expense group(s) have no paid_by_users_id, so nobody could be emailed. '
                . 'The company still owes that money — fix the attribution on those expenses.',
                $skippedTotal,
            ));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return EloquentCollection<int, Expense>
     */
    private function outstandingTenants(Carbon $asOf): EloquentCollection
    {
        $query = Expense::query()
            ->where('is_deleted', 0)
            ->where('paid_by', ExpensePaidByEnum::EMPLOYEE_PERSONAL->value)
            ->where('status', ExpenseStatusEnum::APPROVED->value)
            ->whereIn('reimbursement_status', [
                ExpenseReimbursementStatusEnum::PENDING->value,
                ExpenseReimbursementStatusEnum::APPROVED->value,
            ])
            ->whereDate('approved_at', '<=', $asOf);

        if ($this->option('app') !== null) {
            $query->where('apps_id', (int) $this->option('app'));
        }

        if ($this->option('company') !== null) {
            $query->where('companies_id', (int) $this->option('company'));
        }

        return $query->select('apps_id', 'companies_id')->distinct()->get();
    }
}
