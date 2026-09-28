<?php

declare(strict_types=1);

namespace App\Console\Commands\Movipass;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Enums\AppEnums;
use Kanvas\Inventory\Regions\Enums\CustomFieldEnum as RegionCustomFieldEnum;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\UserCompanyApps;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class BackfillCompanyDefaultRegionCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-movipass:backfill-company-default-region
        {app_id : The app whose companies should be backfilled}
        {--company_id= : Only backfill this company}
        {--dry-run : List what would be written without making changes}';

    protected $description = 'Copy the legacy movipass_region_id company custom field into the generic default_region_id read by RegionResolutionService.';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $skipped = 0;

        foreach ($this->companies($app) as $company) {
            if (! empty($company->get(RegionCustomFieldEnum::DEFAULT_REGION_ID->value))) {
                continue;
            }

            $regionId = $company->get(CustomFieldEnum::COMPANY_REGION_ID->value);

            if (empty($regionId)) {
                continue;
            }

            $region = Regions::query()
                ->fromApp($app)
                ->notDeleted()
                ->where('id', (int) $regionId)
                ->whereIn('companies_id', [AppEnums::GLOBAL_COMPANY_ID->getValue(), $company->getId()])
                ->first();

            if ($region === null) {
                warning(sprintf('%s (ID %d) — movipass_region_id %s is not a reachable region, skipped', $company->name, $company->getId(), $regionId));
                $skipped++;

                continue;
            }

            if (! $dryRun) {
                $company->set(RegionCustomFieldEnum::DEFAULT_REGION_ID->value, $region->getId(), isPublic: 1);
            }

            $rows[] = [$company->getId(), $company->name, $region->getId(), $region->short_slug];
        }

        if (empty($rows)) {
            info('Nothing to backfill.' . ($skipped > 0 ? sprintf(' %d company(ies) skipped.', $skipped) : ''));

            return self::SUCCESS;
        }

        table(['company_id', 'company', 'region_id', 'region'], $rows);
        info(sprintf(
            '%s %d company(ies)%s',
            $dryRun ? 'Would backfill' : 'Backfilled',
            count($rows),
            $skipped > 0 ? sprintf(', %d skipped', $skipped) : ''
        ));

        return self::SUCCESS;
    }

    /**
     * @return iterable<Companies>
     */
    private function companies(Apps $app): iterable
    {
        $companyIds = UserCompanyApps::where('apps_id', $app->getId())
            ->when(
                $this->option('company_id'),
                fn ($query, $companyId) => $query->where('companies_id', (int) $companyId)
            )
            ->distinct()
            ->pluck('companies_id');

        foreach ($companyIds as $companyId) {
            $company = Companies::query()->notDeleted()->find((int) $companyId);

            if ($company !== null) {
                yield $company;
            }
        }
    }
}
