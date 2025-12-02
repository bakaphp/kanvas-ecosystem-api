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
 * This command allows canceling vouchers by their voucher number.
 */
class CancelVoucherCommand extends Command
{
    protected $signature = 'kanvas:ua-cancel-voucher
                            {voucher_id : The voucher number to cancel}
                            {--app-id=22 : App ID to use (default: 22)}
                            {--company-id= : Company ID to use (defaults to first company of app)}
                            {--dry-run : Show what would be done without actually canceling}';

    protected $description = 'Cancel a Universal Assistance voucher by its voucher number';

    public function handle(): int
    {
        $voucherId = $this->argument('voucher_id');
        $appId = (int) $this->option('app-id');
        $companyId = $this->option('company-id');
        $dryRun = $this->option('dry-run');

        $this->info('╔════════════════════════════════════════════════════════════════╗');
        $this->info('║       UNIVERSAL ASSISTANCE - VOUCHER CANCELLATION              ║');
        $this->info('╚════════════════════════════════════════════════════════════════╝');
        $this->line('');

        if ($dryRun) {
            $this->warn('[DRY RUN] No voucher will be canceled');
            $this->line('');
        }

        // Get app
        $app = Apps::find($appId);
        if (! $app) {
            $this->error("App with ID {$appId} not found");

            return self::FAILURE;
        }

        $this->info("Using App: {$app->name} (ID: {$app->id})");

        // Get company
        $company = null;
        if ($companyId) {
            $company = Companies::find($companyId);
            if (! $company) {
                $this->error("Company with ID {$companyId} not found");

                return self::FAILURE;
            }
        } else {
            // Get first company associated with the app
            $company = Companies::where('apps_id', $app->id)->first();
            if (! $company) {
                // Try to get any company
                $company = Companies::first();
            }
        }

        if (! $company) {
            $this->error('No company found');

            return self::FAILURE;
        }

        $this->info("Using Company: {$company->name} (ID: {$company->id})");
        $this->line('');

        $this->info("Voucher to cancel: {$voucherId}");
        $this->line('');

        if ($dryRun) {
            $this->info('[DRY RUN] Would send cancellation request for voucher: ' . $voucherId);
            $this->line('');
            $this->info('Request parameters:');
            $this->line('  AgenciaAnulacion: (organization from app settings)');
            $this->line("  NroVoucherSiebelAnulacion: {$voucherId}");

            return self::SUCCESS;
        }

        // Confirm before proceeding
        if (! $this->confirm("Are you sure you want to cancel voucher {$voucherId}?")) {
            $this->warn('Operation canceled by user');

            return self::SUCCESS;
        }

        try {
            $client = new Client($app, $company);

            $this->info('Sending cancellation request...');
            $this->line('');

            $result = $client->anulaVoucher([
                'voucherNumber' => $voucherId,
            ]);

            $this->info('Response received:');
            $this->line('');

            // Display the response
            $this->displayResponse($result);

            // Check for success/error in response
            $errorCode = $result['UAAnulaVoucherResponse']['DatosAnulaVoucherResp']['ErrorCode']
                ?? $result['DatosAnulaVoucherResp']['ErrorCode']
                ?? $result['ErrorCode']
                ?? null;

            $errorMsg = $result['UAAnulaVoucherResponse']['DatosAnulaVoucherResp']['ErrorMsg']
                ?? $result['DatosAnulaVoucherResp']['ErrorMsg']
                ?? $result['ErrorMsg']
                ?? 'Unknown';

            if ($errorCode === '00' || $errorCode === 0 || $errorCode === '0') {
                $this->info('');
                $this->info('[SUCCESS] Voucher canceled successfully');

                return self::SUCCESS;
            } else {
                $this->error('');
                $this->error("[ERROR] Failed to cancel voucher: {$errorCode} - {$errorMsg}");

                return self::FAILURE;
            }
        } catch (Exception $e) {
            $this->error('');
            $this->error('Exception: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Display the SOAP response in a readable format
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
