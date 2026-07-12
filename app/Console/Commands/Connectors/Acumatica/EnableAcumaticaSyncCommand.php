<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Acumatica;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\Enums\ConfigurationEnum;

/**
 * Opt a company into (or out of) the scheduled Acumatica sync. The scheduler never touches a company
 * that hasn't been enabled here — enablement is the hard gate. Stores everything the scheduler needs
 * (app, Acumatica legal-entity id, attributing user, region) as company settings.
 */
class EnableAcumaticaSyncCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:acumatica-enable-sync
        {app_id : Kanvas app id the Acumatica config lives on}
        {company_id : Kanvas company id (the tenant for this legal entity)}
        {acumatica_company_id : Acumatica CompanyID for the legal entity}
        {--user_id= : Kanvas user to attribute imports to (defaults to the company user)}
        {--region_id= : Kanvas region id for inventory pulls}
        {--disable : Turn the scheduled sync OFF for this company}';

    protected $description = 'Enable or disable the scheduled Acumatica sync for a company.';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'), $app);

        if ($this->option('disable')) {
            $company->set(ConfigurationEnum::SYNC_ENABLED->value, '0');
            $this->info("Acumatica sync DISABLED for company {$company->getId()}.");

            return self::SUCCESS;
        }

        $company->set(ConfigurationEnum::SYNC_ENABLED->value, '1');
        $company->set(ConfigurationEnum::SYNC_APP_ID->value, (string) $app->getId());
        $company->set(ConfigurationEnum::SYNC_COMPANY_ID->value, (string) (int) $this->argument('acumatica_company_id'));

        $userId = $this->option('user_id');
        if (is_numeric($userId)) {
            $company->set(ConfigurationEnum::SYNC_USER_ID->value, (string) (int) $userId);
        }

        $regionId = $this->option('region_id');
        if (is_numeric($regionId)) {
            $company->set(ConfigurationEnum::SYNC_REGION_ID->value, (string) (int) $regionId);
        }

        $this->info("Acumatica sync ENABLED for company {$company->getId()} (Acumatica CompanyID {$this->argument('acumatica_company_id')}).");

        return self::SUCCESS;
    }
}
