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

    protected $description = 'Seed global regions (AR, SV, PR) and configure country map for multi-country Movipass support. DR region must already exist.';

    private const NEW_REGIONS = [
        [
            'name' => 'Argentina',
            'slug' => 'argentina',
            'short_slug' => 'AR',
            'currency_code' => 'ARS',
            'is_default' => 0,
            'lat' => -34.6037,
            'lng' => -58.3816,
            'settings' => [
                'language' => 'es-AR',
                'flag' => '🇦🇷',
                'payment_gateways' => ['payway'],
                'default_payment_gateway' => 'payway',
                'timezone' => 'America/Argentina/Buenos_Aires',
            ],
        ],
        [
            'name' => 'El Salvador',
            'slug' => 'el-salvador',
            'short_slug' => 'SV',
            'currency_code' => 'USD',
            'is_default' => 0,
            'lat' => 13.6929,
            'lng' => -89.2182,
            'settings' => [
                'language' => 'es-SV',
                'flag' => '🇸🇻',
                'payment_gateways' => ['stripe'],
                'default_payment_gateway' => 'stripe',
                'timezone' => 'America/El_Salvador',
            ],
        ],
        [
            'name' => 'Puerto Rico',
            'slug' => 'puerto-rico',
            'short_slug' => 'PR',
            'currency_code' => 'USD',
            'is_default' => 0,
            'lat' => 18.4655,
            'lng' => -66.1057,
            'settings' => [
                'language' => 'es-PR',
                'flag' => '🇵🇷',
                'payment_gateways' => ['stripe'],
                'default_payment_gateway' => 'stripe',
                'timezone' => 'America/Puerto_Rico',
            ],
        ],
    ];

    private const DR_SETTINGS = [
        'language' => 'es-DO',
        'flag' => '🇩🇴',
        'payment_gateways' => ['azul'],
        'default_payment_gateway' => 'azul',
        'timezone' => 'America/Santo_Domingo',
    ];

    private const DR_LAT = 18.4861;
    private const DR_LNG = -69.9312;

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        // DR global region — find by short_slug DO or DR (legacy), normalize name + slug
        $drRegion = Regions::where('apps_id', $app->getId())
            ->where('companies_id', 0)
            ->whereIn('short_slug', ['DO', 'DR'])
            ->where('is_deleted', 0)
            ->firstOrFail();

        $drRegion->name = 'República Dominicana';
        $drRegion->short_slug = 'DO';
        $drRegion->slug = 'dominican-republic';
        $drRegion->is_default = 1;
        $drRegion->settings = self::DR_SETTINGS;
        $drRegion->lat = self::DR_LAT;
        $drRegion->lng = self::DR_LNG;
        $drRegion->save();
        info('Region: República Dominicana (DO) — updated');

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

            $region->settings = $regionData['settings'];
            $region->lat = $regionData['lat'];
            $region->lng = $regionData['lng'];
            $region->save();

            $regionMap[$regionData['short_slug']] = $region->uuid;

            $status = $region->wasRecentlyCreated ? 'created' : 'updated';
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
