<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Acumatica;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\Actions\PullCustomersAction;
use Kanvas\Connectors\Acumatica\Actions\PullProductsAction;
use Kanvas\Connectors\Acumatica\Actions\PullSalesOrdersAction;
use Kanvas\Connectors\Acumatica\Actions\PullStockAction;
use Kanvas\Connectors\Acumatica\Actions\PullWarehousesAction;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Backfill a single Acumatica legal entity into a Kanvas company from the SQL replica.
 * Order matters: warehouses → products → stock (stock needs variant + warehouse to exist).
 */
class PullAcumaticaCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:acumatica-pull
        {app_id : Kanvas app id}
        {company_id : Kanvas company id (the tenant this Acumatica entity maps to)}
        {user_id : Kanvas user to attribute the import to}
        {acumatica_company_id : Acumatica CompanyID for the legal entity (e.g. 2=US)}
        {--region_id= : Kanvas region id (defaults to the app/company first region)}
        {--only=all : products|warehouses|stock|customers|vendors|orders|all}
        {--all-items : include non-stock items (default: stock items only)}
        {--limit= : cap rows pulled per entity (recommended for first runs / large catalogs)}
        {--debug : print per-row diagnostics (stock)}';

    protected $description = 'Pull Acumatica products, warehouses, stock, customers, vendors and sales orders from the SQL replica into a Kanvas company.';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'), $app);
        $user = Users::getById((int) $this->argument('user_id'));
        $acumaticaCompanyId = (int) $this->argument('acumatica_company_id');

        $regionId = $this->option('region_id');
        $region = is_numeric($regionId)
            ? Regions::getByIdFromCompanyApp((int) $regionId, $company, $app)
            : Regions::fromApp($app)->fromCompany($company)->notDeleted()->first();

        if ($region === null) {
            $this->error('No region found for this app/company — pass --region_id.');

            return self::FAILURE;
        }

        $only = is_string($this->option('only')) ? $this->option('only') : 'all';
        $stockItemsOnly = ! (bool) $this->option('all-items');
        $limit = is_numeric($this->option('limit')) ? (int) $this->option('limit') : null;

        try {
            if (in_array($only, ['all', 'warehouses'], true)) {
                $n = new PullWarehousesAction($app, $company, $user, $region, $acumaticaCompanyId, $limit)->execute();
                $this->info("Warehouses synced: {$n}");
            }

            if (in_array($only, ['all', 'products'], true)) {
                $n = new PullProductsAction($app, $company, $user, $region, $acumaticaCompanyId, $stockItemsOnly, $limit)->execute();
                $this->info("Products synced: {$n}");
            }

            if (in_array($only, ['all', 'customers'], true)) {
                $n = new PullCustomersAction($app, $company, $user, $acumaticaCompanyId, limit: $limit)->execute();
                $this->info("Customers synced: {$n}");
            }

            if (in_array($only, ['all', 'vendors'], true)) {
                $n = new PullCustomersAction($app, $company, $user, $acumaticaCompanyId, isVendor: true, limit: $limit)->execute();
                $this->info("Vendors synced: {$n}");
            }

            if (in_array($only, ['all', 'stock'], true)) {
                $stockAction = new PullStockAction($app, $company, $user, $region, $acumaticaCompanyId, $limit);
                $n = $stockAction->execute();
                $this->info("Stock rows updated: {$n}");

                if ((bool) $this->option('debug')) {
                    foreach ($stockAction->log as $row) {
                        $json = json_encode($row);
                        $this->line('  ' . ($json !== false ? $json : ''));
                    }
                }
            }

            if (in_array($only, ['all', 'orders'], true)) {
                $ordersAction = new PullSalesOrdersAction($app, $company, $user, $region, $acumaticaCompanyId, $limit);
                $n = $ordersAction->execute();
                $this->info("Sales orders synced: {$n}");

                if ($n === 0 && $ordersAction->skipped !== []) {
                    $this->warn('Skipped: ' . (json_encode($ordersAction->skipped) ?: '{}'));
                }
            }
        } catch (Throwable $e) {
            $this->error('Acumatica sync failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Acumatica sync complete.');

        return self::SUCCESS;
    }
}
