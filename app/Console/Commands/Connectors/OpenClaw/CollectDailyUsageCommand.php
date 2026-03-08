<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\OpenClaw;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesSettings;
use Kanvas\Connectors\OpenClaw\Actions\CollectDailyUsageAction;
use Kanvas\Connectors\OpenClaw\Actions\CollectHealthSnapshotAction;
use Kanvas\Connectors\OpenClaw\Enums\ConfigurationEnum;
use Throwable;

class CollectDailyUsageCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:openclaw-collect-usage {app_id}';

    protected $description = 'Collect daily usage data from OpenClaw for all configured companies';

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $companyIds = CompaniesSettings::where(
            'name',
            ConfigurationEnum::SSH_HOST->value
        )
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->select('companies_id')
            ->distinct()
            ->pluck('companies_id');

        $this->info('Found ' . $companyIds->count() . ' companies with OpenClaw configured');

        foreach ($companyIds as $companyId) {
            /** @var Companies $company */
            $company = Companies::getById((int) $companyId);

            try {
                $usageSnapshot = new CollectDailyUsageAction($app, $company)->execute();
                $this->info("Collected usage for company {$company->name} (ID: {$companyId}): snapshot #{$usageSnapshot->getId()}");

                $healthSnapshot = new CollectHealthSnapshotAction($app, $company)->execute();
                $this->info("Collected health for company {$company->name} (ID: {$companyId}): snapshot #{$healthSnapshot->getId()}");
            } catch (Throwable $e) {
                $this->error("Failed for company {$companyId}: {$e->getMessage()}");
            }
        }
    }
}
