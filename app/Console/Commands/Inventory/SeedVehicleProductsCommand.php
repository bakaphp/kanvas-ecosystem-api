<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Traits\KanvasJobsTrait;
use Baka\Users\Contracts\UserInterface;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use Faker\Provider\FakeCar;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Baka\Search\SearchEngineResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Kanvas\Apps\Models\Apps as AppsModel;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use Kanvas\Inventory\Categories\Actions\CreateCategory;
use Kanvas\Inventory\Categories\DataTransferObject\Categories as CategoryDto;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Throwable;

class SeedVehicleProductsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:inventory:seed-vehicle-products {app_id} {company_id} {count=10}';

    protected $description = 'Seed fake vehicles as products for a given app and company';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'), $app);
        $user = $company->user;

        $count = (int) $this->argument('count');
        if ($count < 1) {
            $this->error('Count must be a positive integer.');

            return self::FAILURE;
        }

        $faker = FakerFactory::create();
        $faker->addProvider(new FakeCar($faker));

        $vehiclesCategory = new CreateCategory(
            new CategoryDto(
                app: $app,
                company: $company,
                user: $user,
                name: 'Vehicles',
                slug: 'vehicles',
            ),
            $user,
        )->execute();

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $created = 0;
        $createdProducts = [];

        $previousScoutDriver = Config::get('scout.driver');
        Config::set('scout.driver', 'null');
        app(EngineManager::class)->forgetEngines();

        app()->bind(SearchEngineResolver::class, fn () => new class () extends SearchEngineResolver {
            public function __construct()
            {
            }

            public function resolveEngine(?Model $model = null, ?AppsModel $app = null): \Laravel\Scout\Engines\Engine
            {
                return new NullEngine();
            }
        });

        try {
            for ($i = 0; $i < $count; $i++) {
                try {
                    $createdProducts[] = $this->createProduct($faker, $app, $company, $user, $vehiclesCategory->getId());
                    $created++;
                } catch (Throwable $e) {
                    $this->newLine();
                    $this->error('Failed to create vehicle product: ' . $e->getMessage());
                }

                $bar->advance();
            }
        } finally {
            Config::set('scout.driver', $previousScoutDriver);
            app()->forgetInstance(SearchEngineResolver::class);
            app()->bind(SearchEngineResolver::class, SearchEngineResolver::class);
            app(EngineManager::class)->forgetEngines();
        }

        $bar->finish();
        $this->newLine(2);

        foreach ($createdProducts as $product) {
            try {
                $product->refresh()->searchable();
            } catch (Throwable $e) {
                $this->error('Failed to index product #' . $product->getId() . ': ' . $e->getMessage());
            }
        }

        $this->info("Created {$created} of {$count} vehicle products for company #{$company->getId()} on app #{$app->getId()}.");

        return self::SUCCESS;
    }

    private function createProduct(
        Generator $faker,
        Apps $app,
        Companies $company,
        UserInterface $user,
        int $categoryId,
    ): Products {
        $year = $faker->year('now');
        $brand = $faker->vehicleBrand();
        $model = $faker->vehicleModel();
        $vin = $faker->vin();
        $type = $faker->vehicleType();
        $fuelType = $faker->vehicleFuelType();
        $gearBox = $faker->vehicleGearBoxType();
        $registration = $faker->vehicleRegistration();
        $enginePower = $faker->vehicleEnginePower();
        $engineTorque = $faker->vehicleEngineTorque();
        $doorCount = $faker->vehicleDoorCount();
        $seatCount = $faker->vehicleSeatCount();

        $name = trim($year . ' ' . $brand . ' ' . $model);
        $shortDescription = sprintf('%s | %s | %s', $type, $fuelType, $gearBox);
        $description = $name . ' - ' . $shortDescription;

        $attributes = [
            ['name' => 'Year', 'value' => $year],
            ['name' => 'Brand', 'value' => $brand],
            ['name' => 'Model', 'value' => $model],
            ['name' => 'VIN', 'value' => $vin],
            ['name' => 'Type', 'value' => $type],
            ['name' => 'Fuel Type', 'value' => \is_array($fuelType) ? implode(', ', $fuelType) : $fuelType],
            ['name' => 'Gearbox', 'value' => $gearBox],
            ['name' => 'Registration', 'value' => $registration],
            ['name' => 'Engine Power', 'value' => $enginePower],
            ['name' => 'Engine Torque', 'value' => $engineTorque],
            ['name' => 'Doors', 'value' => (string) $doorCount],
            ['name' => 'Seats', 'value' => (string) $seatCount],
        ];

        return new CreateProductAction(
            new ProductDto(
                app: $app,
                company: $company,
                user: $user,
                name: $name,
                description: $description,
                short_description: $shortDescription,
                sku: $vin,
                is_published: true,
                categories: [['id' => $categoryId]],
                attributes: $attributes,
            ),
            $user,
        )->setRunWorkflow(false)->execute();
    }
}
