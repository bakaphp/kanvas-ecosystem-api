<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\DealerAppCenter;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DealerAppCenter\Actions\MapProductToVehicleAction;
use Kanvas\Connectors\DealerAppCenter\Actions\PushVehicleToDealerAction;
use Kanvas\Inventory\Products\Models\Products;

/**
 * CLI wrapper for the reverse migration: Kanvas Products -> dealer-api `vehicles` rows.
 * Mapping/insert logic lives in MapProductToVehicleAction / PushVehicleToDealerAction, shared with
 * PushProductToVehicleActivity (the Workflow-facing entry point for this same migration).
 */
class MigrateProductsToVehiclesCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-connectors:dealer-app-center-migrate-products-to-vehicles
        {app_id : Kanvas app id}
        {company_id : Kanvas company id}
        {rooftop_id : Destination dealer-api rooftop id}
        {--dry-run : Map without inserting into the dealer database}
        {--product_id=* : Limit to specific Kanvas product ids (repeatable)}';

    protected $description = 'Map Kanvas Products (+ variants/attributes) to dealer-api Vehicle rows and insert them directly into the dealer-api MySQL database';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $rooftopId = (int) $this->argument('rooftop_id');
        $dryRun = (bool) $this->option('dry-run');
        $productIds = array_map('intval', $this->option('product_id'));

        $dealerConnection = PushVehicleToDealerAction::resolveDealerConnection();

        $products = Products::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->whereHas('variants')
            ->when($productIds !== [], fn ($query) => $query->whereIn('id', $productIds))
            ->with([
                'attributes.attribute',
                'categories',
                'files',
                'variants.attributes.attribute',
                'variants.variantChannels.warehouse',
                'variants.variantChannels.productVariantWarehouse.warehouse',
                'variants.files',
            ])
            ->get();

        if ($products->isEmpty()) {
            $this->warn('No products with variants found for the given app/company.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($products->count());
        $bar->start();

        foreach ($products as $product) {
            $variant = $product->variants->first();

            $mapped = new MapProductToVehicleAction(
                $product,
                $variant,
                $rooftopId,
                $dealerConnection,
            )->execute();

            if ($dryRun) {
                $this->newLine();
                $this->line(json_encode($mapped, JSON_PRETTY_PRINT));
            } else {
                new PushVehicleToDealerAction($mapped, $dealerConnection)->execute();
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Done. Products processed: ' . $products->count());

        return self::SUCCESS;
    }
}
