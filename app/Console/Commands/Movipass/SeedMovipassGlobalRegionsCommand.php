<?php

declare(strict_types=1);

namespace App\Console\Commands\Movipass;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Regions\Enums\ConfigurationEnum;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;

use function Laravel\Prompts\info;

class SeedMovipassGlobalRegionsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-movipass:seed-global-regions
        {app_id : The app ID to configure global regions for}';

    protected $description = 'Seed global regions (AR, SV) and configure country map for multi-country Movipass support. DR region must already exist.';

    private const NEW_REGIONS = [
        [
            'name' => 'Argentina',
            'slug' => 'argentina',
            'short_slug' => 'AR',
            'currency_code' => 'ARS',
            'is_default' => 0,
        ],
        [
            'name' => 'El Salvador',
            'slug' => 'el-salvador',
            'short_slug' => 'SV',
            'currency_code' => 'USD',
            'is_default' => 0,
        ],
    ];

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        // DR region must already exist — look it up
        $drRegion = Regions::where('apps_id', $app->getId())
            ->where('short_slug', 'DO')
            ->where('is_deleted', 0)
            ->firstOrFail();

        info(sprintf('Region: %s (DO) — already exists', $drRegion->name));

        $regionMap = ['DO' => $drRegion->uuid];
        $defaultRegionUuid = $drRegion->uuid;

        foreach (self::NEW_REGIONS as $regionData) {
            $currency = Currencies::getByCode($regionData['currency_code']);

            // Global regions: companies_id=0, users_id=0 (ownerless, shared across all companies)
            $region = Regions::firstOrCreate(
                [
                    'slug' => $regionData['slug'],
                    'apps_id' => $app->getId(),
                    'companies_id' => 0,
                ],
                [
                    'name' => $regionData['name'],
                    'short_slug' => $regionData['short_slug'],
                    'currency_id' => $currency->getId(),
                    'is_default' => $regionData['is_default'],
                    'users_id' => 0,
                ],
            );

            $regionMap[$regionData['short_slug']] = $region->uuid;

            $status = $region->wasRecentlyCreated ? 'created' : 'already exists';
            info(sprintf(
                'Region: %s (%s, %s) — %s',
                $region->name,
                $regionData['short_slug'],
                $regionData['currency_code'],
                $status,
            ));
        }

        $mapJson = json_encode($regionMap, JSON_THROW_ON_ERROR);
        $app->set(ConfigurationEnum::REGION_COUNTRY_MAP->value, $mapJson);
        info('Set region_country_map: ' . $mapJson);

        $app->set(ConfigurationEnum::DEFAULT_REGION_UUID->value, $defaultRegionUuid);
        info('Set default_region_uuid: ' . $defaultRegionUuid);

        if (! $app->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value)) {
            $app->set(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value, 1);
            info('Enabled ALLOW_CROSS_COMPANY_VARIANTS');
        }

        info('Global regions seeded successfully.');

        return self::SUCCESS;
    }
}
