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
use phpseclib4\Net\SFTP;
use RuntimeException;
use Throwable;

class DownloadWordPressInventoryCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:wordpress-download-inventory
                            {app_id : The application ID}
                            {--dealer= : Optional specific dealer name to process (processes all if omitted)}
                            {--make= : Optional vehicle make to filter by}
                            {--email= : Email address to send results notification to}';

    protected $description = 'Download vehicle inventory from WordPress dealer sites, generate CSV files, upload via FTP/FTPS/SFTP, and notify via email';

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
                    $msg = "Downloaded {$result['total']} unique vehicles";
                    $skippedNoVin = (int) ($result['skipped_no_vin'] ?? 0);
                    $skippedDupeVin = (int) ($result['skipped_duplicate_vin'] ?? 0);
                    if ($skippedNoVin > 0 || $skippedDupeVin > 0) {
                        $msg .= " (skipped: {$skippedNoVin} no-VIN, {$skippedDupeVin} duplicate-VIN)";
                    }
                    $this->info($msg);
                    if ($skippedNoVin > 0 && isset($result['sample_no_vin'])) {
                        $this->warn('Sample no-VIN record: ' . (string) json_encode($result['sample_no_vin'], JSON_PRETTY_PRINT));
                    }
                    if ($skippedDupeVin > 0 && isset($result['sample_duplicate_vin'])) {
                        /** @var array{vin: string, page: int, vehicle: array<string, mixed>} $sampleDupe */
                        $sampleDupe = $result['sample_duplicate_vin'];
                        $this->warn('Sample duplicate-VIN (VIN: ' . $sampleDupe['vin'] . ', repeated on page ' . $sampleDupe['page'] . '): ' . (string) json_encode($sampleDupe['vehicle'], JSON_PRETTY_PRINT));
                    }
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
        $ftpRoot = (string) ($app->get(ConfigurationEnum::FTP_ROOT->value) ?: '/');

        $protocol = strtolower((string) ($app->get(ConfigurationEnum::FTP_PROTOCOL->value) ?: ''));
        if ($protocol === '') {
            $protocol = ((bool) $app->get(ConfigurationEnum::FTP_SSL->value)) ? 'ftps' : 'ftp';
        }

        if (! in_array($protocol, ['ftp', 'ftps', 'sftp'], true)) {
            throw new ValidationException("Unsupported FTP protocol: {$protocol}. Expected ftp, ftps, or sftp.");
        }

        $defaultPort = $protocol === 'sftp' ? 22 : 21;
        $ftpPort = (int) ($app->get(ConfigurationEnum::FTP_PORT->value) ?: $defaultPort);

        $filesToUpload = array_filter(
            array_column($results, 'file_path'),
            fn ($path) => $path !== null && file_exists($path)
        );

        if (empty($filesToUpload)) {
            $this->warn('No files to upload.');

            return false;
        }

        $this->info('Uploading via ' . strtoupper($protocol) . ' to: ' . $ftpHost . ':' . $ftpPort);

        return match ($protocol) {
            'sftp' => $this->uploadViaSftp(
                $ftpHost,
                $ftpPort,
                $ftpUsername,
                $ftpPassword,
                $ftpRoot,
                $filesToUpload
            ),
            'ftp', 'ftps' => $this->uploadViaCurl(
                $protocol,
                $ftpHost,
                $ftpPort,
                $ftpUsername,
                $ftpPassword,
                $ftpRoot,
                $filesToUpload
            ),
        };
    }

    /**
     * @param array<int|string, string> $files
     */
    protected function uploadViaSftp(
        string $host,
        int $port,
        string $username,
        string $password,
        string $root,
        array $files
    ): bool {
        $sftp = new SFTP($host, $port, 30);

        if (! $sftp->login($username, $password)) {
            $this->error('SFTP login failed for user: ' . $username);

            return false;
        }

        $remoteRoot = rtrim($root, '/');
        if ($remoteRoot !== '' && ! $sftp->is_dir($remoteRoot)) {
            try {
                $sftp->mkdir($remoteRoot, -1, true);
            } catch (RuntimeException $e) {
                $this->error("  Could not create remote directory: {$remoteRoot} - " . $e->getMessage());
            }
        }

        $uploaded = 0;
        foreach ($files as $localPath) {
            $remoteName = basename($localPath);
            $remotePath = ($remoteRoot === '' ? '' : $remoteRoot) . '/' . $remoteName;

            $this->info("  Uploading: {$remoteName}");

            // phpseclib4 dropped getLastSFTPError(); a failed put() throws instead of returning false.
            try {
                $sftp->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE);
                $uploaded++;
                $this->info("  Uploaded: {$remoteName}");
            } catch (RuntimeException $e) {
                $this->error("  Failed to upload: {$remoteName} - " . $e->getMessage());
            }
        }

        $sftp->disconnect();

        $this->info("SFTP upload complete: {$uploaded}/" . count($files) . ' files');

        return $uploaded > 0;
    }

    /**
     * @param array<int|string, string> $files
     */
    protected function uploadViaCurl(
        string $scheme,
        string $host,
        int $port,
        string $username,
        string $password,
        string $root,
        array $files
    ): bool {
        $useSsl = $scheme === 'ftps';
        $uploaded = 0;

        foreach ($files as $localPath) {
            $remoteName = basename($localPath);
            $remotePath = rtrim($root, '/') . '/' . $remoteName;
            $ftpUrl = "{$scheme}://{$host}:{$port}{$remotePath}";

            $this->info("  Uploading: {$remoteName}");

            $fileHandle = fopen($localPath, 'r');
            if ($fileHandle === false) {
                $this->error("  Failed to open local file: {$remoteName}");

                continue;
            }

            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $ftpUrl);
                curl_setopt($ch, CURLOPT_USERPWD, "{$username}:{$password}");
                curl_setopt($ch, CURLOPT_UPLOAD, true);
                curl_setopt($ch, CURLOPT_INFILE, $fileHandle);
                curl_setopt($ch, CURLOPT_INFILESIZE, filesize($localPath));
                curl_setopt($ch, CURLOPT_FTP_CREATE_MISSING_DIRS, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
                curl_setopt($ch, CURLOPT_TIMEOUT, 120);

                if ($useSsl) {
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

        $this->info(strtoupper($scheme) . " upload complete: {$uploaded}/" . count($files) . ' files');

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
        $lines[] = 'WordPress Inventory Download - Results';
        $lines[] = "App: {$app->name} (ID: {$app->id})";
        $lines[] = 'Date: ' . now()->toDateTimeString();
        $lines[] = '';
        $lines[] = "Dealers processed successfully: {$totalSuccess}";
        $lines[] = "Dealers failed: {$totalFailed}";
        $lines[] = 'FTP upload: ' . ($ftpUploaded ? 'Yes' : 'No');
        $lines[] = '';
        $lines[] = 'Details:';

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
        $onPage = function (int $page, int $totalPages, int $pageCount, int $totalCount, int $skippedNoVin = 0, int $skippedDupeVin = 0): void {
            $msg = "  Page {$page}/{$totalPages}: {$pageCount} items (unique: {$totalCount})";
            if ($skippedNoVin > 0 || $skippedDupeVin > 0) {
                $msg .= " [no_vin: {$skippedNoVin}, dup_vin: {$skippedDupeVin}]";
            }
            $this->info($msg);
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
                extraParams: is_array($dealer['extra_params'] ?? null) ? $dealer['extra_params'] : [],
                onPage: $onPage,
            );
        }

        $queries = is_array($dealer['queries'] ?? null) ? $dealer['queries'] : [];

        if (count($queries) > 0) {
            $baseUrl = (string) ($dealer['base_url'] ?? '');

            return new DownloadInventoryAction(
                dealerName: $dealerName,
                baseUrl: $baseUrl,
                apiPath: (string) ($dealer['api_path'] ?? ''),
                rooftopId: $dealer['rooftop_id'] ?? null,
                inventoryCatcherName: $dealer['inventory_catcher_name'] ?? null,
                provider: $provider,
                queries: $queries,
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
            extraParams: is_array($dealer['extra_params'] ?? null) ? $dealer['extra_params'] : [],
            onPage: $onPage,
        );
    }
}
