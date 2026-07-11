<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Acumatica;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\Actions\PullBillsAction;
use Kanvas\Connectors\Acumatica\Actions\PullChartOfAccountsAction;
use Kanvas\Connectors\Acumatica\Actions\PullCustomersAction;
use Kanvas\Connectors\Acumatica\Actions\PullFiscalPeriodsAction;
use Kanvas\Connectors\Acumatica\Actions\PullInvoicesAction;
use Kanvas\Connectors\Acumatica\Actions\PullJournalEntriesAction;
use Kanvas\Connectors\Acumatica\Actions\PullProductsAction;
use Kanvas\Connectors\Acumatica\Actions\PullPurchaseOrdersAction;
use Kanvas\Connectors\Acumatica\Actions\PullSalesOrdersAction;
use Kanvas\Connectors\Acumatica\Actions\PullStockAction;
use Kanvas\Connectors\Acumatica\Actions\PullSubaccountsAction;
use Kanvas\Connectors\Acumatica\Actions\PullWarehousesAction;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Backfill a single Acumatica legal entity into a Kanvas company from the SQL replica.
 * Order matters: warehouses → products → stock (stock needs variant + warehouse to exist), and
 * accounts → periods → journal-entries (a JE can't post without its account + an open period).
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
        {--only=all : products|warehouses|stock|customers|vendors|purchase-orders|orders|accounts|subaccounts|periods|journal-entries|invoices|bills|all}
        {--all-items : include non-stock items (default: stock items only)}
        {--limit= : cap rows pulled per entity (recommended for first runs / large catalogs)}
        {--order-number= : pull a single sales order by its number (orders only)}
        {--debug : print per-row diagnostics (stock)}';

    protected $description = 'Pull Acumatica products, warehouses, stock, customers, vendors, sales orders and GL (accounts, fiscal periods, journal entries) from the SQL replica into a Kanvas company.';

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

            if (in_array($only, ['all', 'purchase-orders'], true)) {
                $poAction = new PullPurchaseOrdersAction($app, $company, $user, $acumaticaCompanyId, $limit);
                $n = $poAction->execute();
                $this->info("Purchase orders synced: {$n}");

                if ($n === 0) {
                    $this->warnSkipped($poAction->skipped);
                }
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
                $orderNumber = is_string($this->option('order-number')) ? $this->option('order-number') : null;
                $ordersAction = new PullSalesOrdersAction($app, $company, $user, $region, $acumaticaCompanyId, $limit, orderNumber: $orderNumber);
                $n = $ordersAction->execute();
                $this->info("Sales orders synced: {$n}");

                if ($n === 0) {
                    $this->warnSkipped($ordersAction->skipped);
                }
            }

            if (in_array($only, ['all', 'accounts'], true)) {
                $accountsAction = new PullChartOfAccountsAction($app, $company, $user, $acumaticaCompanyId, $limit);
                $n = $accountsAction->execute();
                $this->info("Accounts synced: {$n}");

                if ($n === 0) {
                    $this->warnSkipped($accountsAction->skipped);
                }
            }

            if (in_array($only, ['all', 'subaccounts'], true)) {
                $subaccountsAction = new PullSubaccountsAction($app, $company, $user, $acumaticaCompanyId, $limit);
                $n = $subaccountsAction->execute();
                $this->info("Subaccounts synced: {$n}");

                if ($n === 0) {
                    $this->warnSkipped($subaccountsAction->skipped);
                }
            }

            if (in_array($only, ['all', 'periods'], true)) {
                $periodsAction = new PullFiscalPeriodsAction($app, $company, $user, $acumaticaCompanyId, $limit);
                $n = $periodsAction->execute();
                $this->info("Fiscal periods synced: {$n}");

                if ($n === 0) {
                    $this->warnSkipped($periodsAction->skipped);
                }
            }

            if (in_array($only, ['all', 'journal-entries'], true)) {
                $journalAction = new PullJournalEntriesAction($app, $company, $user, $acumaticaCompanyId, $limit);
                $n = $journalAction->execute();
                $this->info("Journal entries synced: {$n}");

                if ($n === 0) {
                    $this->warnSkipped($journalAction->skipped);
                }
            }

            if (in_array($only, ['all', 'invoices'], true)) {
                $invoicesAction = new PullInvoicesAction($app, $company, $user, $acumaticaCompanyId, $limit);
                $n = $invoicesAction->execute();
                $this->info("Invoices synced: {$n}");

                if ($n === 0) {
                    $this->warnSkipped($invoicesAction->skipped);
                }
            }

            if (in_array($only, ['all', 'bills'], true)) {
                $billsAction = new PullBillsAction($app, $company, $user, $acumaticaCompanyId, $limit);
                $n = $billsAction->execute();
                $this->info("Bills synced: {$n}");

                if ($n === 0) {
                    $this->warnSkipped($billsAction->skipped);
                }
            }
        } catch (Throwable $e) {
            $this->error('Acumatica sync failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Acumatica sync complete.');

        return self::SUCCESS;
    }

    /**
     * @param array<string, int> $skipped
     */
    private function warnSkipped(array $skipped): void
    {
        if ($skipped === []) {
            return;
        }

        $json = json_encode($skipped);
        $this->warn('Skipped: ' . ($json !== false ? $json : '{}'));
    }
}
