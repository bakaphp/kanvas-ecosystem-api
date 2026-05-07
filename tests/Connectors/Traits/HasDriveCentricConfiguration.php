<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DriveCentric\Client;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Kanvas\Regions\Models\Regions;

trait HasDriveCentricConfiguration
{
    protected ?Regions $testRegion = null;

    public function setupDriveCentricClient(AppInterface $app, ?Companies $company = null): Client
    {
        $company = $company ?? Companies::first();

        $app->set(ConfigurationEnum::BASE_URL->value, getenv('TEST_DRIVE_CENTRIC_BASE_URL'));
        $app->set(ConfigurationEnum::API_KEY->value, getenv('TEST_DRIVE_CENTRIC_API_KEY'));
        $app->set(ConfigurationEnum::API_SECRET_KEY->value, getenv('TEST_DRIVE_CENTRIC_API_SECRET_KEY'));
        $company->set(ConfigurationEnum::STORE_ID->value, getenv('TEST_DRIVE_CENTRIC_STORE_ID'));

        return new Client($app, $company);
    }

    public function getTestRegion(AppInterface $app, Companies $company): Regions
    {
        if ($this->testRegion === null) {
            $this->testRegion = Regions::firstOrCreate([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'name' => 'DriveCentric Test Region',
            ], [
                'currency_id' => 1,
            ]);
        }

        return $this->testRegion;
    }
}
