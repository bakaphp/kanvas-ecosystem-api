<?php

declare(strict_types=1);

namespace App\Console\Commands\Search;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\UserCompanyApps;
use Throwable;

class IndexCompaniesRecordsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:index-companies {app_id}';

    protected $description = 'Reindex company records for the given app';

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);

        // Pluck the ids rather than a subquery — Companies and the pivot may be on different
        // connections, which would break an in-SQL subquery.
        $companyIds = UserCompanyApps::query()
            ->where('apps_id', $app->getId())
            ->distinct()
            ->pluck('companies_id');

        $companies = Companies::query()
            ->whereIn('id', $companyIds)
            ->where('is_deleted', 0);

        $total = $companies->count();
        $this->info("Reindexing {$total} companies for app {$app->name}");
        $this->output->progressStart($total);

        $companies->chunkById(100, function ($chunk): void {
            foreach ($chunk as $company) {
                try {
                    $company->searchable();
                    $this->output->progressAdvance();
                } catch (Throwable $e) {
                    $this->error("Error reindexing company ID {$company->id}: " . $e->getMessage());
                }
            }
        });

        $this->output->progressFinish();
        $this->info("Total companies reindexed: {$total}");
    }
}
