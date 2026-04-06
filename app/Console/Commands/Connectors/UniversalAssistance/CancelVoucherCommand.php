<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\UniversalAssistance;

use Exception;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\UniversalAssistance\Client;

/**
 * Command to cancel/void Universal Assistance vouchers.
 *
 * This command allows canceling one or many vouchers by their voucher numbers.
 * Supports batch processing via multiple arguments, --ids, or --file.
 */
class CancelVoucherCommand extends Command
{
    protected $signature = 'kanvas:ua-cancel-voucher
                            {voucher_ids?* : Voucher numbers to cancel (space-separated, or use --ids for comma-separated)}
                            {--ids= : Comma-separated list of voucher numbers to cancel}
                            {--file= : Path to a file containing voucher numbers (one per line)}
                            {--company-id= : Company ID to use}
                            {--app-id=22 : App ID to use (default: 22)}
                            {--revert-charge=N : Revert charge Y/N (default: N)}
                            {--batch-size=50 : Number of vouchers to process per batch}
                            {--dry-run : Show what would be done without actually canceling}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Cancel Universal Assistance vouchers by their voucher numbers (supports batch)';

    protected int $canceledCount = 0;
    protected int $errorCount = 0;
    protected array $canceledVouchers = [];
    protected array $errorVouchers = [];

    public function handle(): int
    {
        $voucherIds = $this->resolveVoucherIds();
        $appId = (int) $this->option('app-id');
        $companyId = $this->option('company-id');
        $revertCharge = strtoupper($this->option('revert-charge') ?? 'N');
        $dryRun = $this->option('dry-run');
        $batchSize = (int) $this->option('batch-size');
        $force = $this->option('force');

        if (empty($voucherIds)) {
            $this->error('You must provide at least one voucher number');
            $this->line('');
            $this->line('Usage:');
            $this->line('  php artisan kanvas:ua-cancel-voucher V123 --company-id=1');
            $this->line('  php artisan kanvas:ua-cancel-voucher V123 V456 V789 --company-id=1');
            $this->line('  php artisan kanvas:ua-cancel-voucher --ids=V123,V456,V789 --company-id=1');
            $this->line('  php artisan kanvas:ua-cancel-voucher --file=voucher_ids.txt --company-id=1');
            $this->line('  php artisan kanvas:ua-cancel-voucher --ids=V123,V456 --company-id=1 --dry-run');
            $this->line('  php artisan kanvas:ua-cancel-voucher --ids=V123,V456 --company-id=1 --batch-size=25 --force');

            return self::FAILURE;
        }

        if (! $companyId) {
            $this->error('You must provide --company-id');

            return self::FAILURE;
        }

        $companyId = (int) $companyId;

        $this->info('╔════════════════════════════════════════════════════════════════╗');
        $this->info('║       UNIVERSAL ASSISTANCE - VOUCHER CANCELLATION              ║');
        $this->info('╚════════════════════════════════════════════════════════════════╝');
        $this->line('');

        if ($dryRun) {
            $this->warn('[DRY RUN] No vouchers will be canceled');
            $this->line('');
        }

        // Get app
        $app = Apps::getById($appId);
        $this->info("Using App: {$app->name} (ID: {$app->id})");

        // Get company
        $company = Companies::getById($companyId);
        $this->info("Using Company: {$company->name} (ID: {$company->id})");
        $this->line('');

        $totalVouchers = count($voucherIds);
        $this->info("Vouchers to cancel: {$totalVouchers}");
        $this->info("Revert charge (UARevertirCobro): {$revertCharge}");
        $this->info("Batch size: {$batchSize}");
        $this->line('');

        if ($dryRun) {
            $this->info('[DRY RUN] Would send cancellation requests for:');
            foreach ($voucherIds as $vid) {
                $this->line("  - {$vid}");
            }
            $this->line('');
            $this->info('Request parameters per voucher:');
            $this->line("  UARevertirCobro: {$revertCharge}");
            $this->line('  AgenciaAnulacion: (organization from app settings)');
            $this->line('');

            $this->canceledCount = $totalVouchers;
            $this->canceledVouchers = $voucherIds;
            $this->printSummary($dryRun);

            return self::SUCCESS;
        }

        // Confirm before proceeding
        if (! $force && ! $this->confirm("Are you sure you want to cancel {$totalVouchers} voucher(s)?")) {
            $this->warn('Operation canceled by user');

            return self::SUCCESS;
        }

        try {
            $client = new Client($app, $company);

            $this->processBatchVouchers($voucherIds, $client, $revertCharge, $batchSize);

            $this->printSummary($dryRun);

            return $this->errorCount > 0 ? self::FAILURE : self::SUCCESS;
        } catch (Exception $e) {
            $this->error('Fatal error: ' . $e->getMessage());
            $this->line($e->getTraceAsString());

            return self::FAILURE;
        }
    }

    /**
     * Resolve voucher IDs from all input sources: arguments, --ids, and --file.
     */
    protected function resolveVoucherIds(): array
    {
        $ids = [];

        // From positional arguments (space-separated)
        $argIds = $this->argument('voucher_ids');
        if (! empty($argIds)) {
            foreach ($argIds as $id) {
                foreach (explode(',', (string) $id) as $subId) {
                    $trimmed = trim($subId);
                    if ($trimmed !== '') {
                        $ids[] = $trimmed;
                    }
                }
            }
        }

        // From --ids option (comma-separated)
        $idsOption = $this->option('ids');
        if ($idsOption) {
            foreach (explode(',', $idsOption) as $id) {
                $trimmed = trim($id);
                if ($trimmed !== '') {
                    $ids[] = $trimmed;
                }
            }
        }

        // From --file option (one ID per line)
        $filePath = $this->option('file');
        if ($filePath) {
            if (! file_exists($filePath)) {
                $this->error("File not found: {$filePath}");

                return [];
            }

            $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                foreach (explode(',', $line) as $id) {
                    $trimmed = trim($id);
                    if (str_starts_with($trimmed, '#') || $trimmed === '') {
                        continue;
                    }
                    $ids[] = $trimmed;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Process vouchers in batches.
     */
    protected function processBatchVouchers(array $voucherIds, Client $client, string $revertCharge, int $batchSize): void
    {
        $totalVouchers = count($voucherIds);
        $chunks = array_chunk($voucherIds, $batchSize);
        $totalBatches = count($chunks);

        $progressBar = $this->output->createProgressBar($totalVouchers);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
        $progressBar->setMessage('Starting...');
        $progressBar->start();

        foreach ($chunks as $batchIndex => $chunk) {
            $batchNum = $batchIndex + 1;
            $progressBar->setMessage("Batch {$batchNum}/{$totalBatches}");

            foreach ($chunk as $voucherId) {
                $progressBar->setMessage("Voucher {$voucherId} (Batch {$batchNum}/{$totalBatches})");
                $this->cancelVoucher($voucherId, $client, $revertCharge);
                $progressBar->advance();
            }
        }

        $progressBar->setMessage('Complete!');
        $progressBar->finish();
        $this->line('');
        $this->line('');
    }

    /**
     * Cancel a single voucher.
     */
    protected function cancelVoucher(string $voucherId, Client $client, string $revertCharge): void
    {
        try {
            $result = $client->anulaVoucher([
                'voucherNumber' => $voucherId,
                'revertirCobro' => $revertCharge,
            ]);

            $errorCode = $result['ErrorCodeAnulacion']
                ?? $result['Anula_Voucher_Operation_Output']['ErrorCodeAnulacion']
                ?? null;

            $errorMsg = $result['ErrorMsgAnulacion']
                ?? $result['Anula_Voucher_Operation_Output']['ErrorMsgAnulacion']
                ?? 'Unknown';

            if ($errorCode === '00' || $errorCode === 0 || $errorCode === '0' || $errorCode === '') {
                $this->canceledCount++;
                $this->canceledVouchers[] = $voucherId;
            } else {
                $this->errorCount++;
                $this->errorVouchers[] = ['id' => $voucherId, 'error' => "{$errorCode} - {$errorMsg}"];
            }
        } catch (Exception $e) {
            $this->errorCount++;
            $this->errorVouchers[] = ['id' => $voucherId, 'error' => $e->getMessage()];
        }
    }

    /**
     * Print summary of the cancellation operation.
     */
    protected function printSummary(bool $dryRun): void
    {
        $this->line('');
        $this->info('╔════════════════════════════════════════════════════════════════╗');
        $this->info('║                     CANCELLATION SUMMARY                       ║');
        $this->info('╚════════════════════════════════════════════════════════════════╝');
        $this->line('');

        if ($dryRun) {
            $this->warn('[DRY RUN] No vouchers were actually canceled');
            $this->line('');
        }

        $label = $dryRun ? 'Would cancel' : 'Canceled';
        $this->info("  {$label}: {$this->canceledCount}");

        if ($this->errorCount > 0) {
            $this->error("  Errors:    {$this->errorCount}");
        } else {
            $this->info("  Errors:    {$this->errorCount}");
        }

        if (! empty($this->canceledVouchers)) {
            $this->line('');
            $canceledLabel = $dryRun ? 'Vouchers that would be canceled' : 'Vouchers canceled';
            $this->info("  {$canceledLabel} (" . count($this->canceledVouchers) . '):');
            foreach ($this->canceledVouchers as $vid) {
                $this->line("    - {$vid}");
            }
        }

        if (! empty($this->errorVouchers)) {
            $this->line('');
            $this->error('  Failed vouchers (' . count($this->errorVouchers) . '):');
            foreach ($this->errorVouchers as $ev) {
                $this->line("    - {$ev['id']}: {$ev['error']}");
            }
        }

        $this->line('');
    }

    /**
     * Display the SOAP response in a readable format.
     */
    protected function displayResponse(array $response, int $indent = 0): void
    {
        $prefix = str_repeat('  ', $indent);

        foreach ($response as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $this->line("{$prefix}{$key}:");
                $this->displayResponse((array) $value, $indent + 1);
            } else {
                $this->line("{$prefix}{$key}: {$value}");
            }
        }
    }
}
