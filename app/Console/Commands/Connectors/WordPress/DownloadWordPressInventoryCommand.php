<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\WordPress;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WordPress\Actions\DownloadInventoryAction;
use Kanvas\Connectors\WordPress\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Throwable;

class DownloadWordPressInventoryCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:wordpress-download-inventory
                            {app_id : The application ID}
                            {--dealer= : Optional specific dealer name to process (processes all if omitted)}
                            {--make= : Optional vehicle make to filter by}';

    protected $description = 'Download vehicle inventory from WordPress dealer sites and generate CSV files';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $dealers = $app->get(ConfigurationEnum::DEALERS->value);

        if (empty($dealers) || ! is_array($dealers)) {
            $this->error('No WordPress dealers configured. Set the "' . ConfigurationEnum::DEALERS->value . '" app setting.');

            return Command::FAILURE;
        }

        $specificDealer = $this->option('dealer');
        $make = $this->option('make');

        if ($specificDealer !== null) {
            $dealers = array_filter($dealers, fn (array $d) => ($d['name'] ?? '') === $specificDealer);
            if (empty($dealers)) {
                $this->error("Dealer '{$specificDealer}' not found in configuration.");

                return Command::FAILURE;
            }
        }

        $dealers = array_values($dealers);
        $dealerCount = count($dealers);

        $this->info('WordPress Inventory Download');
        $this->info('App: ' . $app->name . ' (ID: ' . $app->id . ')');
        $this->info('Dealers to process: ' . $dealerCount);
        if ($make) {
            $this->info('Filtering by make: ' . $make);
        }
        $this->newLine();

        $totalSuccess = 0;
        $totalFailed = 0;
        $results = [];

        foreach ($dealers as $index => $dealer) {
            $dealerName = (string) ($dealer['name'] ?? 'Unknown');
            $provider = (string) ($dealer['provider'] ?? 'wp');
            $dealerMake = $make ?? ($dealer['filter_make'] ?? null);

            $this->info("Processing dealer: {$dealerName} ({$provider}) (" . ($index + 1) . "/{$dealerCount})");

            try {
                $action = $this->buildAction($dealer, $dealerName, $provider, $dealerMake);

                $result = $action->execute();
                $results[] = $result;

                if ($result['file_path'] !== null) {
                    $this->info("Downloaded {$result['total']} vehicles");
                    $this->info("CSV: {$result['file_path']}");
                    $totalSuccess++;
                } else {
                    $this->warn($result['message']);
                    $totalSuccess++;
                }
            } catch (Throwable $e) {
                $this->error("Failed: {$e->getMessage()}");
                $totalFailed++;
                $results[] = [
                    'success' => false,
                    'dealer' => $dealerName,
                    'total' => 0,
                    'file_path' => null,
                    'message' => $e->getMessage(),
                ];
            }

            $this->newLine();

            if ($index < $dealerCount - 1) {
                sleep(2);
            }
        }

        $this->info('=== Summary ===');
        $this->info("Dealers processed: {$totalSuccess}");
        if ($totalFailed > 0) {
            $this->error("Dealers failed: {$totalFailed}");
        }

        foreach ($results as $r) {
            $status = $r['success'] ? 'OK' : 'FAIL';
            $this->info("[{$status}] {$r['dealer']}: {$r['message']}");
        }

        return $totalFailed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    protected function buildAction(array $dealer, string $dealerName, string $provider, mixed $dealerMake): DownloadInventoryAction
    {
        $onPage = function (int $page, int $totalPages, int $pageCount, int $totalCount): void {
            $this->info("  Page {$page}/{$totalPages}: {$pageCount} items (total: {$totalCount})");
        };

        if ($provider === 'algolia') {
            return new DownloadInventoryAction(
                dealerName: $dealerName,
                baseUrl: '',
                apiPath: '',
                rooftopId: $dealer['rooftop_id'] ?? null,
                inventoryCatcherName: $dealer['inventory_catcher_name'] ?? null,
                filterMake: is_string($dealerMake) ? $dealerMake : null,
                provider: 'algolia',
                algoliaAppId: $dealer['algolia_app_id'] ?? null,
                algoliaApiKey: $dealer['algolia_api_key'] ?? null,
                algoliaIndexName: $dealer['algolia_index_name'] ?? null,
                onPage: $onPage,
            );
        }

        if ($provider === 'widget') {
            $baseUrl = (string) ($dealer['base_url'] ?? '');
            if ($baseUrl === '') {
                throw new ValidationException(
                    "Dealer '{$dealerName}': missing base_url for widget provider."
                );
            }

            return new DownloadInventoryAction(
                dealerName: $dealerName,
                baseUrl: $baseUrl,
                apiPath: '',
                rooftopId: $dealer['rooftop_id'] ?? null,
                inventoryCatcherName: $dealer['inventory_catcher_name'] ?? null,
                filterMake: is_string($dealerMake) ? $dealerMake : null,
                provider: 'widget',
                widgetSiteId: $dealer['widget_site_id'] ?? null,
                widgetListingConfigId: $dealer['widget_listing_config_id'] ?? null,
                onPage: $onPage,
            );
        }

        $baseUrl = (string) ($dealer['base_url'] ?? '');
        $apiPath = (string) ($dealer['api_path'] ?? '');

        if ($baseUrl === '' || $apiPath === '') {
            throw new ValidationException(
                "Dealer '{$dealerName}': missing base_url or api_path in configuration."
            );
        }

        return new DownloadInventoryAction(
            dealerName: $dealerName,
            baseUrl: $baseUrl,
            apiPath: $apiPath,
            rooftopId: $dealer['rooftop_id'] ?? null,
            inventoryCatcherName: $dealer['inventory_catcher_name'] ?? null,
            filterMake: is_string($dealerMake) ? $dealerMake : null,
            onPage: $onPage,
        );
    }
}
