<?php

declare(strict_types=1);

namespace App\Console\Commands\Guild;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Duplicates\Actions\DetectDuplicatesAction;
use Throwable;

/**
 * Tenant tuples come from `organizations`/`peoples` directly (not a companies table scan) — no
 * point sweeping a tenant with zero Guild records.
 */
class DetectGuildDuplicatesCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:guild:detect-duplicates
        {--app= : Restrict to a single apps_id}
        {--company= : Restrict to a single companies_id}';

    protected $description = 'Sweep Guild Organizations + People for likely duplicates and queue them for review.';

    public function handle(): int
    {
        $appOption = $this->option('app') !== null ? (int) $this->option('app') : null;
        $companyOption = $this->option('company') !== null ? (int) $this->option('company') : null;

        $organizationTuples = DB::connection('crm')->table('organizations')
            ->select('apps_id', 'companies_id')
            ->where('is_deleted', 0);
        $peopleTuples = DB::connection('crm')->table('peoples')
            ->select('apps_id', 'companies_id')
            ->where('is_deleted', 0);

        foreach ([$organizationTuples, $peopleTuples] as $query) {
            if ($appOption !== null) {
                $query->where('apps_id', $appOption);
            }
            if ($companyOption !== null) {
                $query->where('companies_id', $companyOption);
            }
        }

        $tuples = $organizationTuples->union($peopleTuples)->get();

        if ($tuples->isEmpty()) {
            $this->info('No Guild Organizations/People found — nothing to sweep.');

            return self::SUCCESS;
        }

        $createdTotal = 0;
        $failed = 0;

        foreach ($tuples as $row) {
            $appId = (int) $row->apps_id;
            $companyId = (int) $row->companies_id;

            try {
                $app = Apps::getById($appId);
                $this->overwriteAppService($app);
                $company = Companies::getById($companyId);
            } catch (Throwable $e) {
                $this->warn(sprintf('apps_id=%d companies_id=%d → tenant resolution failed: %s', $appId, $companyId, $e->getMessage()));
                $failed++;

                continue;
            }

            try {
                $result = new DetectDuplicatesAction($app, $company)->execute();
                $createdTotal += $result['created'];
                $this->line(sprintf(
                    '  app=%-3d company=%-4d created=%-3d skipped=%d',
                    $appId,
                    $companyId,
                    $result['created'],
                    $result['skipped'],
                ));
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error(sprintf('  app=%d company=%d failed: %s', $appId, $companyId, $e->getMessage()));
            }
        }

        $this->info(sprintf(
            'Done. Queued %d new duplicate group(s) across %d tenant(s). Failures: %d',
            $createdTotal,
            count($tuples),
            $failed,
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
