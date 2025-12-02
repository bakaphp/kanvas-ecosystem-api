<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\UniversalAssistance;

use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\UniversalAssistance\Services\InsuranceWorkflowService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Models\Order;

/**
 * Process pending insurance data from orders and create vouchers.
 *
 * This command processes insurancePendingData stored in order metadata
 * and creates Universal Assistance vouchers for each pending insurance.
 */
class ProcessPendingInsuranceCommand extends Command
{
    protected $signature = 'kanvas:process-pending-insurance
                            {order_id? : Optional Order ID to process}
                            {--all : Process all orders with pending insurance data for app 22}
                            {--dry-run : Show what would be processed without actually creating vouchers}
                            {--app-id=22 : App ID to use (default: 22)}';

    protected $description = 'Process pending insurance data from orders and create Universal Assistance vouchers';

    protected int $processedCount = 0;
    protected int $skippedCount = 0;
    protected int $errorCount = 0;
    protected array $processedOrders = [];
    protected array $errorOrders = [];

    public function handle(): int
    {
        $orderId = $this->argument('order_id');
        $processAll = $this->option('all');
        $dryRun = $this->option('dry-run');
        $appId = (int) $this->option('app-id');

        if (! $orderId && ! $processAll) {
            $this->error('You must provide an order_id or use --all option');
            $this->line('');
            $this->line('Usage:');
            $this->line('  php artisan kanvas:process-pending-insurance 12345');
            $this->line('  php artisan kanvas:process-pending-insurance --all');
            $this->line('  php artisan kanvas:process-pending-insurance --all --dry-run');

            return self::FAILURE;
        }

        $this->info('╔════════════════════════════════════════════════════════════════╗');
        $this->info('║     UNIVERSAL ASSISTANCE - PENDING INSURANCE PROCESSOR         ║');
        $this->info('╚════════════════════════════════════════════════════════════════╝');
        $this->line('');

        if ($dryRun) {
            $this->warn('[DRY RUN] No vouchers will be created');
            $this->line('');
        }

        // Get app
        $app = Apps::find($appId);
        if (! $app) {
            $this->error("App with ID {$appId} not found");

            return self::FAILURE;
        }

        $this->info("Using App: {$app->name} (ID: {$app->id})");
        $this->line('');

        try {
            if ($orderId) {
                // Process single order
                $this->processSingleOrder((int) $orderId, $app, $dryRun);
            } else {
                // Process all orders with pending insurance
                $this->processAllPendingOrders($app, $dryRun);
            }

            $this->printSummary();

            return $this->errorCount > 0 ? self::FAILURE : self::SUCCESS;
        } catch (Exception $e) {
            $this->error('Fatal error: ' . $e->getMessage());
            $this->line($e->getTraceAsString());

            return self::FAILURE;
        }
    }

    /**
     * Process a single order by ID
     */
    protected function processSingleOrder(int $orderId, Apps $app, bool $dryRun): void
    {
        $this->info("Looking for Order ID: {$orderId}");

        $order = Order::where('id', $orderId)
            ->where('apps_id', $app->id)
            ->first();

        if (! $order) {
            $this->error("Order with ID {$orderId} not found in App {$app->id}");
            $this->errorCount++;

            return;
        }

        $this->processOrder($order, $app, $dryRun);
    }

    /**
     * Process all orders with pending insurance data
     */
    protected function processAllPendingOrders(Apps $app, bool $dryRun): void
    {
        $this->info("Searching for orders with pending insurance data in App {$app->id}...");
        $this->line('');

        // Find orders that have insurancePendingData in their metadata
        $orders = Order::where('apps_id', $app->id)
            ->where('is_deleted', false)
            ->whereRaw("JSON_EXTRACT(metadata, '$.new_data.data.insurancePendingData') IS NOT NULL")
            ->orderBy('id', 'desc')
            ->get();

        if ($orders->isEmpty()) {
            $this->warn('No orders found with pending insurance data');

            return;
        }

        $this->info("Found {$orders->count()} orders with pending insurance data");
        $this->line('');

        $progressBar = $this->output->createProgressBar($orders->count());
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
        $progressBar->setMessage('Starting...');
        $progressBar->start();

        foreach ($orders as $order) {
            $progressBar->setMessage("Processing Order #{$order->id}");
            $this->processOrder($order, $app, $dryRun, true);
            $progressBar->advance();
        }

        $progressBar->setMessage('Complete!');
        $progressBar->finish();
        $this->line('');
        $this->line('');
    }

    /**
     * Process a single order
     */
    protected function processOrder(Order $order, Apps $app, bool $dryRun, bool $silent = false): void
    {
        $orderId = $order->id;

        if (! $silent) {
            $this->line('');
            $this->info("═══════════════════════════════════════════════════════════");
            $this->info("Processing Order #{$orderId}");
            $this->info("═══════════════════════════════════════════════════════════");
        }

        // Get metadata
        $metadata = $order->metadata ?? [];
        if (is_object($metadata)) {
            $metadata = json_decode(json_encode($metadata), true);
        }

        // Check for insurancePendingData
        $insurancePendingData = $metadata['new_data']['data']['insurancePendingData'] ?? [];

        if (empty($insurancePendingData)) {
            if (! $silent) {
                $this->warn("[SKIP] No insurancePendingData found in Order #{$orderId}");
            }
            $this->skippedCount++;

            return;
        }

        if (! $silent) {
            $this->info("Found " . count($insurancePendingData) . " pending insurance entries");
        }

        // Process each pending insurance
        $orderResults = [];
        $entryIndex = 0;

        foreach ($insurancePendingData as $index => $pendingData) {
            if (! isset($pendingData['insurance'])) {
                if (! $silent) {
                    $this->warn("  [WARN] Entry #{$entryIndex}: No insurance data - skipping");
                }
                $entryIndex++;

                continue;
            }

            $insurance = $pendingData['insurance'];
            $messageId = isset($pendingData['messageId']) ? (int) $pendingData['messageId'] : null;
            $iccid = $pendingData['iccid'] ?? null;

            // Try to find messageId by ICCID if not present
            if (! $messageId && $iccid) {
                $messageId = $this->findMessageIdByIccid($iccid, $app);
                if (! $silent && $messageId) {
                    $this->info("  Found message ID {$messageId} from ICCID {$iccid}");
                }
            }

            $orderMarkedProcessed = (bool) $order->get('universal_assistance_processed');
            $messageHasExistingVoucher = $messageId && $this->messageHasVoucher((int) $messageId);

            if ($orderMarkedProcessed && $messageHasExistingVoucher) {
                if (! $silent) {
                    $this->warn("  [SKIP] Entry #{$entryIndex}: Order marked processed AND Message #{$messageId} has voucher");
                }
                $this->skippedCount++;
                $entryIndex++;

                continue;
            }

            // Skip if message already has voucher (regardless of order status)
            if ($messageHasExistingVoucher) {
                if (! $silent) {
                    $this->warn("  [SKIP] Entry #{$entryIndex}: Message #{$messageId} already has voucher");
                }
                $this->skippedCount++;
                $entryIndex++;

                continue;
            }

            // Log if order is marked processed but message has no voucher (we'll process)
            if (! $silent && $orderMarkedProcessed) {
                $this->info("  [INFO] Order marked processed but message has no voucher - will process");
            }

            // Validate and adjust dates
            $titular = $insurance['titular'] ?? [];
            $dateValidation = $this->validateAndAdjustDates($titular, $silent, $entryIndex);

            if ($dateValidation['skip']) {
                if (! $silent) {
                    $this->warn("  [SKIP] Entry #{$entryIndex}: {$dateValidation['reason']}");
                }
                $this->skippedCount++;
                $entryIndex++;

                continue;
            }

            // Update dates in insurance data if adjusted or calculated
            if ($dateValidation['adjusted'] || ! isset($insurance['titular']['expirationDate'])) {
                $insurance['titular']['activationDate'] = $dateValidation['activationDate'];
                $insurance['titular']['expirationDate'] = $dateValidation['expirationDate'];

                if (! $silent && $dateValidation['adjusted']) {
                    $this->info("  Adjusted dates: {$dateValidation['activationDate']} to {$dateValidation['expirationDate']}");
                }
            }

            // Display insurance info
            if (! $silent) {
                $this->displayInsuranceInfo($insurance, $entryIndex);
            }

            if ($dryRun) {
                if (! $silent) {
                    $this->info("  [DRY RUN] Would create voucher for entry #{$entryIndex}");
                }
                $this->processedCount++;
                $entryIndex++;

                continue;
            }

            // Create voucher
            try {
                $result = $this->createVoucher($order, $app, $insurance, $messageId, $entryIndex, $silent);
                $orderResults["entry_{$entryIndex}"] = $result;

                if ($result['success']) {
                    $this->processedCount++;
                    if (! $silent) {
                        $voucherId = $result['voucher_id'] ?? 'N/A';
                        $this->info("  [OK] Voucher created successfully: {$voucherId}");
                    }
                } else {
                    $this->errorCount++;
                    if (! $silent) {
                        $this->error("  [ERROR] Failed to create voucher: " . ($result['error'] ?? 'Unknown error'));
                    }
                }
            } catch (Exception $e) {
                $this->errorCount++;
                if (! $silent) {
                    $this->error("  [ERROR] Exception: " . $e->getMessage());
                }
                $orderResults["entry_{$entryIndex}"] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }

            $entryIndex++;
        }

        // Mark order as processed if we had any successful vouchers
        $successfulVouchers = array_filter($orderResults, fn ($r) => $r['success'] ?? false);
        if (! empty($successfulVouchers) && ! $dryRun) {
            $order->set('universal_assistance_processed', true);
            $this->processedOrders[] = $orderId;
        }

        if (! empty($orderResults) && ! $dryRun) {
            // Store results in order metadata
            $metadata['insurance_processing_results'] = [
                'processed_at' => now()->toISOString(),
                'results' => $orderResults,
            ];
            $order->metadata = $metadata;
            $order->saveOrFail();
        }
    }

    /**
     * Validate and adjust insurance dates
     * If expirationDate is missing, calculate it from activationDate + plan.duration
     */
    protected function validateAndAdjustDates(array $titular, bool $silent, int $index): array
    {
        $activationDate = $titular['activationDate'] ?? null;
        $expirationDate = $titular['expirationDate'] ?? null;
        $planDuration = $titular['plan']['duration'] ?? null;

        // If no activationDate, we can't proceed
        if (! $activationDate) {
            return [
                'skip' => true,
                'reason' => 'Missing activation date',
                'adjusted' => false,
            ];
        }

        // Calculate expirationDate from plan duration if missing
        if (! $expirationDate && $planDuration) {
            try {
                $activation = Carbon::parse($activationDate)->startOfDay();
                $expirationDate = $activation->copy()->addDays((int) $planDuration - 1)->format('Y-m-d');
                if (! $silent) {
                    $this->info("  Calculated expiration date from plan duration ({$planDuration} days): {$expirationDate}");
                }
            } catch (Exception $e) {
                return [
                    'skip' => true,
                    'reason' => "Failed to calculate expiration date: {$e->getMessage()}",
                    'adjusted' => false,
                ];
            }
        }

        // If still no expirationDate, we can't proceed
        if (! $expirationDate) {
            return [
                'skip' => true,
                'reason' => 'Missing expiration date and no plan duration to calculate it',
                'adjusted' => false,
            ];
        }

        try {
            $activation = Carbon::parse($activationDate)->startOfDay();
            $expiration = Carbon::parse($expirationDate)->startOfDay();
            $today = Carbon::today();

            // Calculate intended duration for recalculation if needed
            $intendedDuration = $planDuration ? (int) $planDuration : $activation->diffInDays($expiration) + 1;

            // Case 1: Both dates in the past
            if ($expiration->lt($today)) {
                return [
                    'skip' => true,
                    'reason' => "Insurance period already expired (ended: {$expiration->format('Y-m-d')})",
                    'adjusted' => false,
                ];
            }

            // Case 2: Start date in the past but end date still valid
            // Recalculate expiration based on today + intended duration
            if ($activation->lt($today) && $expiration->gte($today)) {
                $newExpiration = $today->copy()->addDays($intendedDuration - 1);

                if (! $silent) {
                    $this->warn("  [WARN] Entry #{$index}: Start date expired, adjusting to today with {$intendedDuration} days duration");
                }

                return [
                    'skip' => false,
                    'adjusted' => true,
                    'activationDate' => $today->format('Y-m-d'),
                    'expirationDate' => $newExpiration->format('Y-m-d'),
                    'reason' => null,
                ];
            }

            // Case 3: Both dates valid (in future or today)
            return [
                'skip' => false,
                'adjusted' => false,
                'activationDate' => $activation->format('Y-m-d'),
                'expirationDate' => $expiration->format('Y-m-d'),
                'reason' => null,
            ];
        } catch (Exception $e) {
            return [
                'skip' => true,
                'reason' => "Invalid date format: {$e->getMessage()}",
                'adjusted' => false,
            ];
        }
    }

    /**
     * Display insurance information
     */
    protected function displayInsuranceInfo(array $insurance, int $index): void
    {
        $titular = $insurance['titular'] ?? [];

        $this->line('');
        $this->info("  Entry #{$index} - Insurance Details:");
        $this->line("     ├─ Name: " . ($titular['firstname'] ?? 'N/A') . ' ' . ($titular['lastname'] ?? ''));
        $this->line("     ├─ Email: " . ($titular['email'] ?? 'N/A'));
        $this->line("     ├─ DOB: " . ($titular['dob'] ?? 'N/A'));
        $this->line("     ├─ ID: " . ($titular['idType'] ?? '') . ' ' . ($titular['idNumber'] ?? 'N/A'));
        $this->line("     ├─ Origin: " . ($titular['originCountryCode'] ?? 'N/A'));
        $this->line("     ├─ Destination: " . ($titular['destinationCountryCode'] ?? 'N/A'));
        $this->line("     ├─ Activation: " . ($titular['activationDate'] ?? 'N/A'));
        $this->line("     ├─ Expiration: " . ($titular['expirationDate'] ?? 'N/A'));

        $plan = $titular['plan'] ?? [];
        $this->line("     └─ Plan: " . ($plan['name'] ?? 'N/A'));

        $dependents = $insurance['dependents'] ?? [];
        if (! empty($dependents)) {
            $this->line("     └─ Dependents: " . count($dependents));
        }
    }

    /**
     * Create voucher using InsuranceWorkflowService
     * Mimics processeSIMWithPlanGrouping from ProcessInsuranceCartActivity
     */
    protected function createVoucher(Order $order, Apps $app, array $insurance, ?int $messageId, int $index, bool $silent): array
    {
        $service = new InsuranceWorkflowService($app, $order, $messageId);

        // Convert any objects to arrays to prevent stdClass errors
        $insurance = $this->convertObjectsToArrays($insurance);

        // Validate required fields for titular
        $titular = $insurance['titular'] ?? [];
        $requiredFields = ['firstname', 'lastname', 'idType', 'idNumber', 'dob', 'sex', 'email', 'activationDate', 'originCountryCode', 'destinationCountryCode'];
        $missingFields = [];

        foreach ($requiredFields as $field) {
            if (empty($titular[$field])) {
                $missingFields[] = "titular.{$field}";
            }
        }

        // Check required fields for dependents
        if (isset($insurance['dependents']) && ! empty($insurance['dependents'])) {
            $dependentRequiredFields = ['firstname', 'lastname', 'idType', 'idNumber', 'dob', 'sex', 'relationship'];
            foreach ($insurance['dependents'] as $depIndex => $dependent) {
                foreach ($dependentRequiredFields as $field) {
                    if (empty($dependent[$field])) {
                        $missingFields[] = "dependents[{$depIndex}].{$field}";
                    }
                }
            }
        }

        if (! empty($missingFields)) {
            return [
                'success' => false,
                'error' => 'Missing required fields: ' . implode(', ', $missingFields),
            ];
        }

        // Build grouped persons data like processeSIMWithPlanGrouping does
        $familyGroupKey = 'family_group_command_' . $index;
        $groupedPersonsData = [];

        // Add titular - normalize to only include fields needed by the service
        $normalizedTitular = [
            'firstname' => $titular['firstname'],
            'lastname' => $titular['lastname'],
            'idType' => $titular['idType'],
            'idNumber' => $titular['idNumber'],
            'dob' => $titular['dob'],
            'sex' => $titular['sex'],
            'email' => $titular['email'],
            'activationDate' => $titular['activationDate'],
            'expirationDate' => $titular['expirationDate'] ?? null,
            'originCountryCode' => $titular['originCountryCode'],
            'destinationCountryCode' => $titular['destinationCountryCode'],
            'originCountryName' => $titular['originCountryName'] ?? null,
            'destinationCountryName' => $titular['destinationCountryName'] ?? null,
            'plan' => $titular['plan'] ?? [],
        ];

        $groupedPersonsData[] = $normalizedTitular;

        // Add dependents to the same family group
        if (isset($insurance['dependents']) && ! empty($insurance['dependents'])) {
            foreach ($insurance['dependents'] as $dependent) {
                $normalizedDependent = [
                    'firstname' => $dependent['firstname'],
                    'lastname' => $dependent['lastname'],
                    'idType' => $dependent['idType'],
                    'idNumber' => $dependent['idNumber'],
                    'dob' => $dependent['dob'],
                    'sex' => $dependent['sex'],
                    'email' => $dependent['email'] ?? null,
                    'relationship' => $dependent['relationship'],
                    'activationDate' => $dependent['activationDate'] ?? $titular['activationDate'],
                    'expirationDate' => $dependent['expirationDate'] ?? $titular['expirationDate'] ?? null,
                    'originCountryCode' => $dependent['originCountryCode'] ?? $titular['originCountryCode'],
                    'destinationCountryCode' => $dependent['destinationCountryCode'] ?? $titular['destinationCountryCode'],
                    'originCountryName' => $dependent['originCountryName'] ?? null,
                    'destinationCountryName' => $dependent['destinationCountryName'] ?? null,
                    'plan' => $dependent['plan'] ?? $titular['plan'] ?? [],
                ];

                $groupedPersonsData[] = $normalizedDependent;
            }
        }

        // Process using processGroupedInsuranceWorkflow (same as activity does)
        try {
            $groupResult = $service->processGroupedInsuranceWorkflow($groupedPersonsData, $familyGroupKey);

            // Extract voucher ID from group result
            $voucherId = $this->extractVoucherId([
                'titular' => [
                    'voucher_result' => $groupResult['group_voucher_result'] ?? [],
                ],
            ]);

            return [
                'success' => true,
                'voucher_id' => $voucherId,
                'result' => $groupResult,
                'group_size' => count($groupedPersonsData),
            ];
        } catch (ValidationException $e) {
            return [
                'success' => false,
                'error' => 'Validation error: ' . $e->getMessage(),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Processing error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Convert objects to arrays recursively
     */
    protected function convertObjectsToArrays(mixed $data): mixed
    {
        if (is_object($data)) {
            $data = (array) $data;
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->convertObjectsToArrays($value);
            }
        }

        return $data;
    }

    /**
     * Extract voucher ID from result
     */
    protected function extractVoucherId(array $result): ?string
    {
        // Try multiple paths to find voucher ID based on actual response structure
        // Path 1: From group_voucher_result (processGroupedInsuranceWorkflow)
        $paths = [
            // Group voucher result paths
            $result['group_voucher_result']['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher'] ?? null,
            $result['group_voucher_result']['voucher_data']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher'] ?? null,
            $result['group_voucher_result']['voucher_id'] ?? null,
            $result['group_voucher_result']['voucher_data']['nro_voucher'] ?? null,
            // Titular result paths (backward compatibility)
            $result['titular']['voucher_result']['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher'] ?? null,
            $result['titular']['voucher_result']['voucher_data']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher'] ?? null,
            $result['titular']['voucher_result']['voucher_data']['response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher'] ?? null,
            $result['titular']['voucher_result']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher'] ?? null,
            $result['titular']['voucher_result']['voucher_id'] ?? null,
            $result['titular']['voucher_result']['voucher_data']['voucher_id'] ?? null,
            $result['titular']['voucher_result']['voucher_data']['nro_voucher'] ?? null,
            $result['titular']['voucher_id'] ?? null,
            $result['voucher_id'] ?? null,
        ];

        foreach ($paths as $voucherId) {
            if ($voucherId !== null && $voucherId !== '') {
                return (string) $voucherId;
            }
        }

        return null;
    }

    /**
     * Find message ID by ICCID
     */
    protected function findMessageIdByIccid(string $iccid, Apps $app): ?int
    {
        try {
            $message = Message::where('apps_id', $app->id)
                ->where('message', 'LIKE', '%' . $iccid . '%')
                ->first();

            return $message?->id;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Check if message already has a voucher
     */
    protected function messageHasVoucher(int $messageId): bool
    {
        try {
            $message = Message::find($messageId);
            if (! $message) {
                return false;
            }

            $messageData = $message->message ?? [];

            return isset($messageData['universalAssistanceData']['holder']['nro_voucher']) &&
                   ! empty($messageData['universalAssistanceData']['holder']['nro_voucher']);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Print summary
     */
    protected function printSummary(): void
    {
        $this->line('');
        $this->info('╔════════════════════════════════════════════════════════════════╗');
        $this->info('║                        PROCESSING SUMMARY                       ║');
        $this->info('╚════════════════════════════════════════════════════════════════╝');
        $this->line('');

        $this->info("  Processed: {$this->processedCount}");
        $this->warn("  Skipped:   {$this->skippedCount}");

        if ($this->errorCount > 0) {
            $this->error("  Errors:    {$this->errorCount}");
        } else {
            $this->info("  Errors:    {$this->errorCount}");
        }

        if (! empty($this->processedOrders)) {
            $this->line('');
            $this->info('  Orders processed: ' . implode(', ', $this->processedOrders));
        }

        $this->line('');
    }
}
