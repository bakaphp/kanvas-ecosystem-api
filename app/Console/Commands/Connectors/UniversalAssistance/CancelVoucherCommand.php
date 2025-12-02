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
                            {company_id : Company ID to use}
                            {--app-id=22 : App ID to use (default: 22)}
                            {--revert-charge=N : Revert charge Y/N (default: N)}
                            {--dry-run : Show what would be done without actually canceling}';

    protected $description = 'Cancel a Universal Assistance voucher by its voucher number';

    public function handle(): int
    {
        $voucherId = $this->argument('voucher_id');
        $appId = (int) $this->option('app-id');
        $companyId = (int) $this->argument('company_id');
        $revertCharge = strtoupper($this->option('revert-charge') ?? 'N');
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
        $app = Apps::getById($appId);
        $this->info("Using App: {$app->name} (ID: {$app->id})");

        // Get company
        $company = Companies::getById($companyId);
        $this->info("Using Company: {$company->name} (ID: {$company->id})");
        $this->line('');

        $this->info("Voucher to cancel: {$voucherId}");
        $this->info("Revert charge (UARevertirCobro): {$revertCharge}");
        $this->line('');

        if ($dryRun) {
            $this->info('[DRY RUN] Would send cancellation request for voucher: ' . $voucherId);
            $this->line('');
            $this->info('Request parameters:');
            $this->line("  UARevertirCobro: {$revertCharge}");
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
                'revertirCobro' => $revertCharge,
            ]);

            $this->info('Response received:');
            $this->line('');

            // Display the response
            $this->displayResponse($result);

            // Check for success/error in response (WSDL fields: ErrorCodeAnulacion, ErrorMsgAnulacion)
            $errorCode = $result['ErrorCodeAnulacion']
                ?? $result['Anula_Voucher_Operation_Output']['ErrorCodeAnulacion']
                ?? null;

            $errorMsg = $result['ErrorMsgAnulacion']
                ?? $result['Anula_Voucher_Operation_Output']['ErrorMsgAnulacion']
                ?? 'Unknown';

            if ($errorCode === '00' || $errorCode === 0 || $errorCode === '0' || $errorCode === '') {
                $this->info('');
                $this->info('[SUCCESS] Voucher canceled successfully');
                $this->info("Response: {$errorCode} - {$errorMsg}");

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
