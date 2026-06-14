<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SuperCarros\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Illuminate\Support\Collection;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesSettings;
use Kanvas\Connectors\SuperCarros\Enums\ConfigurationEnum;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\UserCompanyApps;
use Throwable;

class SuperCarrosImportAllCompaniesAction
{
    public function __construct(
        protected AppInterface $app,
        protected bool $unpublishAllBeforeImport = false,
        protected ?float $weight = null,
        protected ?UserInterface $user = null,
        protected ?Regions $region = null,
    ) {
    }

    /**
     * Run the vehicle import for every company in the app that has a SuperCarros
     * customer id configured. Region and user default to each company's own default
     * region and owner, so a single cron that only receives the app id replaces one
     * cron per company.
     */
    public function execute(): array
    {
        $companies = self::getConfiguredCompanies($this->app);

        $aggregate = [
            'companies_total' => $companies->count(),
            'companies_succeeded' => 0,
            'companies_failed' => 0,
            'total_processed' => 0,
            'imported' => 0,
            'failed' => 0,
            'errors' => [],
            'per_company' => [],
        ];

        foreach ($companies as $company) {
            try {
                /** @var Regions|null $region */
                $region = $this->region ?: Regions::getDefault($company, $this->app);
                if (! $region) {
                    throw new Exception('No default region configured for this company.');
                }

                /** @var UserInterface|null $user */
                $user = $this->user ?: $company->user;
                if (! $user) {
                    throw new Exception('No owner user found for this company.');
                }

                // Each company resolves its own access key + customer id from its settings,
                // so customerId is left null here on purpose.
                $result = new SuperCarrosVehicleInventoryImportAction(
                    $this->app,
                    $company,
                    $user,
                    $region,
                    null,
                    null,
                    $this->unpublishAllBeforeImport,
                    null,
                    $this->weight,
                )->execute();

                $aggregate['total_processed'] += (int) $result['total_processed'];
                $aggregate['imported'] += (int) $result['imported'];
                $aggregate['failed'] += (int) $result['failed'];
                $result['success'] ? $aggregate['companies_succeeded']++ : $aggregate['companies_failed']++;

                foreach ($result['errors'] as $error) {
                    $aggregate['errors'][] = $company->name . ' (ID ' . $company->id . '): ' . $error;
                }

                $aggregate['per_company'][] = [
                    'company_id' => $company->id,
                    'company_name' => $company->name,
                    'success' => $result['success'],
                    'total_processed' => $result['total_processed'],
                    'imported' => $result['imported'],
                    'failed' => $result['failed'],
                ];
            } catch (Throwable $e) {
                $aggregate['companies_failed']++;
                $aggregate['errors'][] = $company->name . ' (ID ' . $company->id . '): ' . $e->getMessage();
                $aggregate['per_company'][] = [
                    'company_id' => $company->id,
                    'company_name' => $company->name,
                    'success' => false,
                    'total_processed' => 0,
                    'imported' => 0,
                    'failed' => 0,
                ];
            }
        }

        return $aggregate;
    }

    /**
     * Companies belonging to the app that have a SuperCarros customer id configured.
     *
     * @return Collection<int, Companies>
     */
    public static function getConfiguredCompanies(AppInterface $app): Collection
    {
        $companyIds = UserCompanyApps::where('apps_id', $app->getId())
            ->pluck('companies_id')
            ->unique();

        $configuredIds = CompaniesSettings::whereIn('companies_id', $companyIds)
            ->where('name', ConfigurationEnum::CUSTOMER_ID->value)
            ->whereNotNull('value')
            ->pluck('companies_id')
            ->unique();

        return Companies::whereIn('id', $configuredIds)
            ->notDeleted()
            ->get();
    }
}
