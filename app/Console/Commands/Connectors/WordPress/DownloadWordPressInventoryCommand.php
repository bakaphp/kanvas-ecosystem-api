<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\WordPress;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
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
                            {--make= : Optional vehicle make to filter by}
                            {--email= : Email address to send results notification to}';

    protected $description = 'Download vehicle inventory from WordPress dealer sites, generate CSV files, upload to FTP, and notify via email';

    public function handle(): int
    {
        /** @var Apps $app */
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
        /** @var array<int, array{success: bool, dealer: string, total: int, file_path: string|null, message: string}> $results */
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

        $ftpUploaded = $this->uploadToFtp($app, $results);

        $this->info('=== Summary ===');
        $this->info("Dealers processed: {$totalSuccess}");
        if ($totalFailed > 0) {
            $this->error("Dealers failed: {$totalFailed}");
        }
        if ($ftpUploaded) {
            $this->info('FTP upload: completed');
        }

        foreach ($results as $r) {
            $status = $r['success'] ? 'OK' : 'FAIL';
            $this->info("[{$status}] {$r['dealer']}: {$r['message']}");
        }

        /** @var string|null $email */
        $email = $this->option('email');
        if ($email !== null && $email !== '') {
            /** @psalm-suppress MixedArgumentTypeCoercion */
            $this->sendResultsEmail(
                $email,
                $app,
                $results,
                $totalSuccess,
                $totalFailed,
                $ftpUploaded
            );
        }

        return $totalFailed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    protected function uploadToFtp(Apps $app, array $results): bool
    {
        /** @var string|null $ftpHost */
        $ftpHost = $app->get(ConfigurationEnum::FTP_HOST->value);

        if ($ftpHost === null || $ftpHost === '') {
            $this->warn('No FTP configuration found. Skipping FTP upload.');

            return false;
        }

        $ftpUsername = (string) $app->get(ConfigurationEnum::FTP_USERNAME->value);
        $ftpPassword = (string) $app->get(ConfigurationEnum::FTP_PASSWORD->value);
        $ftpPort = (int) ($app->get(ConfigurationEnum::FTP_PORT->value) ?: 21);
        $ftpRoot = (string) ($app->get(ConfigurationEnum::FTP_ROOT->value) ?: '/');
        $ftpSsl = (bool) $app->get(ConfigurationEnum::FTP_SSL->value);

        $filesToUpload = array_filter(
            array_column($results, 'file_path'),
            fn ($path) => $path !== null && file_exists($path)
        );

        if (empty($filesToUpload)) {
            $this->warn('No files to upload to FTP.');

            return false;
        }

        $this->info('Uploading to FTP: ' . $ftpHost . ':' . $ftpPort);

        $scheme = $ftpSsl ? 'ftps' : 'ftp';
        $uploaded = 0;

        foreach ($filesToUpload as $localPath) {
            $remoteName = basename($localPath);
            $remotePath = rtrim($ftpRoot, '/') . '/' . $remoteName;
            $ftpUrl = "{$scheme}://{$ftpHost}:{$ftpPort}{$remotePath}";

            $this->info("  Uploading: {$remoteName}");

            $fileHandle = fopen($localPath, 'r');
            if ($fileHandle === false) {
                $this->error("  Failed to open local file: {$remoteName}");

                continue;
            }

            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $ftpUrl);
                curl_setopt($ch, CURLOPT_USERPWD, "{$ftpUsername}:{$ftpPassword}");
                curl_setopt($ch, CURLOPT_UPLOAD, true);
                curl_setopt($ch, CURLOPT_INFILE, $fileHandle);
                curl_setopt($ch, CURLOPT_INFILESIZE, filesize($localPath));
                curl_setopt($ch, CURLOPT_FTP_CREATE_MISSING_DIRS, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
                curl_setopt($ch, CURLOPT_TIMEOUT, 120);

                if ($ftpSsl) {
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                }

                $success = curl_exec($ch);
                $error = curl_error($ch);
                curl_close($ch);

                if ($success) {
                    $uploaded++;
                    $this->info("  Uploaded: {$remoteName}");
                } else {
                    $this->error("  Failed to upload: {$remoteName} - {$error}");
                }
            } finally {
                fclose($fileHandle);
            }
        }

        $this->info("FTP upload complete: {$uploaded}/" . count($filesToUpload) . ' files');

        return $uploaded > 0;
    }

    /**
     * @param array<int, array{success: bool, dealer: string, total: int, file_path: string|null, message: string}> $results
     */
    protected function sendResultsEmail(
        string $email,
        Apps $app,
        array $results,
        int $totalSuccess,
        int $totalFailed,
        bool $ftpUploaded
    ): void {
        $this->info("Sending results email to: {$email}");

        $lines = [];
        $lines[] = "WordPress Inventory Download - Results";
        $lines[] = "App: {$app->name} (ID: {$app->id})";
        $lines[] = "Date: " . now()->toDateTimeString();
        $lines[] = "";
        $lines[] = "Dealers processed successfully: {$totalSuccess}";
        $lines[] = "Dealers failed: {$totalFailed}";
        $lines[] = "FTP upload: " . ($ftpUploaded ? 'Yes' : 'No');
        $lines[] = "";
        $lines[] = "Details:";

        foreach ($results as $r) {
            $status = $r['success'] ? 'OK' : 'FAIL';
            $vehicles = $r['total'] > 0 ? " ({$r['total']} vehicles)" : '';
            $lines[] = "  [{$status}] {$r['dealer']}{$vehicles}: {$r['message']}";
        }

        $body = implode("\n", $lines);

        try {
            Mail::raw($body, function (Message $message) use ($email, $totalFailed) {
                $status = $totalFailed > 0 ? 'Completed with errors' : 'Completed successfully';
                $message->to($email)
                    ->subject("WordPress Inventory Download - {$status}");
            });

            $this->info('Email sent successfully.');
        } catch (Throwable $e) {
            $this->error('Failed to send email: ' . $e->getMessage());
        }
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

        if ($provider === 'ajax') {
            $baseUrl = (string) ($dealer['base_url'] ?? '');
            if ($baseUrl === '') {
                throw new ValidationException(
                    "Dealer '{$dealerName}': missing base_url for ajax provider."
                );
            }

            return new DownloadInventoryAction(
                dealerName: $dealerName,
                baseUrl: $baseUrl,
                apiPath: '',
                rooftopId: $dealer['rooftop_id'] ?? null,
                inventoryCatcherName: $dealer['inventory_catcher_name'] ?? null,
                filterMake: is_string($dealerMake) ? $dealerMake : null,
                provider: 'ajax',
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
