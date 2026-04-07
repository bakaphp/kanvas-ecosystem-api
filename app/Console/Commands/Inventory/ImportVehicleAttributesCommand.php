<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Attributes\Actions\AddAttributeValue;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Attributes\Models\AttributesValues;

class ImportVehicleAttributesCommand extends Command
{
    protected $signature = 'kanvas:import-vehicle-attributes
        {--app_id= : The app ID to import attributes for}
        {--user_id= : The user ID for attribution (optional, uses first active user if not provided)}';

    protected $description = 'Import vehicle makes and models as product attributes from NHTSA API';

    protected const string NHTSA_BASE_URL = 'https://vpic.nhtsa.dot.gov/api/vehicles';

    public function handle(): void
    {
        $appId = $this->option('app_id');

        if (! $appId) {
            $this->error('--app_id is required');

            return;
        }

        $app = Apps::getById((int) $appId);

        $userId = (int) ($this->option('user_id') ?? 0);


        $this->info("Importing vehicle attributes for App: {$app->name} (ID: {$app->getId()})");

        $makeAttribute = Attributes::firstOrCreate(
            [
                'slug' => 'make',
                'apps_id' => $app->getId(),
                'companies_id' => 0,
            ],
            [
                'users_id' => $userId,
                'name' => 'Make',
                'is_visible' => true,
                'is_searchable' => true,
                'is_filtrable' => true,
            ]
        );

        $this->info("Attribute 'Make' ready (ID: {$makeAttribute->getId()})");

        $modelAttribute = Attributes::firstOrCreate(
            [
                'slug' => 'model',
                'apps_id' => $app->getId(),
                'companies_id' => 0,
            ],
            [
                'users_id' => $userId,
                'name' => 'Model',
                'is_visible' => true,
                'is_searchable' => true,
                'is_filtrable' => true,
            ]
        );

        $this->info("Attribute 'Model' ready (ID: {$modelAttribute->getId()})");

        $this->info('Fetching vehicle makes from NHTSA API...');
        $makesResponse = Http::get(self::NHTSA_BASE_URL . '/GetMakesForVehicleType/car?format=json');

        if (! $makesResponse->successful()) {
            $this->error('Failed to fetch makes from NHTSA API');

            return;
        }

        $makes = $makesResponse->json('Results', []);
        $this->info('Total makes found: ' . count($makes));

        $bar = $this->output->createProgressBar(count($makes));
        $bar->start();

        foreach ($makes as $make) {
            $makeName = trim($make['MakeName']);

            new AddAttributeValue(
                $makeAttribute,
                [['value' => $makeName, 'parent_id' => null]],
            )->execute();

            $makeValue = AttributesValues::where('attributes_id', $makeAttribute->getId())
                ->whereNull('parent_id')
                ->where('value->en', $makeName)
                ->first();

            if (! $makeValue) {
                $bar->advance();

                continue;
            }

            $modelsResponse = Http::get(
                self::NHTSA_BASE_URL . '/getmodelsformake/' . urlencode($makeName) . '?format=json'
            );

            if ($modelsResponse->successful()) {
                $models = $modelsResponse->json('Results', []);

                if (! empty($models)) {
                    $modelValues = array_map(
                        fn ($model) => [
                            'value' => trim($model['Model_Name']),
                            'parent_id' => $makeValue->getId(),
                        ],
                        $models
                    );

                    new AddAttributeValue(
                        $modelAttribute,
                        $modelValues,
                    )->execute();
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Vehicle attributes imported successfully!');
    }
}
