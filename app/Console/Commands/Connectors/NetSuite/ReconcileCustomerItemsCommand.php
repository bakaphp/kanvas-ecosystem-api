<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\NetSuite;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\NetSuite\Actions\SyncAllNetSuiteCustomerItemsListAction;

class ReconcileCustomerItemsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'netsuite:reconcile-customer-items
        {app_id : The app ID to scope reconciliation to}
        {--dry-run : Diff only, do not re-run sync}
        {--company=* : Limit to specific buyer company IDs (comma-separated or repeatable)}';

    protected $description = 'Reconcile NetSuite customer items list against channel product variants';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));

        $this->overwriteAppService($app);

        $companyIds = [];
        foreach ((array) $this->option('company') as $value) {
            foreach (explode(',', (string) $value) as $id) {
                $id = trim($id);
                if ($id !== '' && is_numeric($id)) {
                    $companyIds[] = (int) $id;
                }
            }
        }
        $companyIds = array_values(array_unique($companyIds));

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN — no changes will be made');
        }

        $output = new SyncAllNetSuiteCustomerItemsListAction(
            $app,
            (bool) $this->option('dry-run'),
            $companyIds
        )->execute();

        $rows = array_map(function (array $entry): array {
            $status = $entry['error'] !== null
                ? ($entry['error']['status'] ?? 'error')
                : 'ok';

            return [
                $entry['company_id'],
                $entry['company_name'],
                $entry['channel_found'] ? 'yes' : 'no',
                $entry['product_count'],
                $entry['channel_count'],
                $entry['missing_count'],
                $entry['synced'] ? 'yes' : 'no',
                $status,
            ];
        }, $output['results']);

        $this->table(
            ['ID', 'Name', 'Channel', 'Products', 'In Channel', 'Missing', 'Synced', 'Status'],
            $rows
        );

        $this->info(sprintf(
            'Total: %d buyers, %d synced, %d skipped, %d errors',
            $output['total_buyers'],
            $output['total_synced'],
            $output['total_skipped'],
            $output['total_errors']
        ));

        return $output['total_errors'] > 0 ? 1 : 0;
    }
}
