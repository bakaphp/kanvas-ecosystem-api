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
                            {order_ids?* : Order IDs to process (space-separated, or use --ids for comma-separated)}
                            {--ids= : Comma-separated list of order IDs to process}
                            {--file= : Path to a file containing order IDs (one per line)}
                            {--all : Process all orders with pending insurance data}
                            {--batch-size=50 : Number of orders to process per batch}
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
        $orderIds = $this->resolveOrderIds();
        $processAll = $this->option('all');
        $dryRun = $this->option('dry-run');
        $appId = (int) $this->option('app-id');
        $batchSize = (int) $this->option('batch-size');

        if (empty($orderIds) && ! $processAll) {
            $this->error('You must provide order IDs or use --all option');
            $this->line('');
            $this->line('Usage:');
            $this->line('  php artisan kanvas:process-pending-insurance 123 456 789');
            $this->line('  php artisan kanvas:process-pending-insurance --ids=123,456,789,101,102');
            $this->line('  php artisan kanvas:process-pending-insurance --file=order_ids.txt');
            $this->line('  php artisan kanvas:process-pending-insurance --all');
            $this->line('  php artisan kanvas:process-pending-insurance --all --dry-run');
            $this->line('  php artisan kanvas:process-pending-insurance --ids=1,2,3 --batch-size=25');

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
            if (! empty($orderIds)) {
                $this->info('Processing ' . count($orderIds) . ' specific orders in batches of ' . $batchSize);
                $this->line('');
                $this->processBatchOrders($orderIds, $app, $dryRun, $batchSize);
            } else {
                $this->processAllPendingOrders($app, $dryRun, $batchSize);
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
     * Resolve order IDs from all input sources: arguments, --ids, and --file.
     */
    protected function resolveOrderIds(): array
    {
        $ids = [];

        // From positional arguments (space-separated)
        $argIds = $this->argument('order_ids');
        if (! empty($argIds)) {
            foreach ($argIds as $id) {
                // Support comma-separated within each argument too
                foreach (explode(',', (string) $id) as $subId) {
                    $trimmed = trim($subId);
                    if ($trimmed !== '' && is_numeric($trimmed)) {
                        $ids[] = (int) $trimmed;
                    }
                }
            }
        }

        // From --ids option (comma-separated)
        $idsOption = $this->option('ids');
        if ($idsOption) {
            foreach (explode(',', $idsOption) as $id) {
                $trimmed = trim($id);
                if ($trimmed !== '' && is_numeric($trimmed)) {
                    $ids[] = (int) $trimmed;
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
                // Support comma-separated within lines too
                foreach (explode(',', $line) as $id) {
                    $trimmed = trim($id);
                    // Skip comment lines
                    if (str_starts_with($trimmed, '#') || $trimmed === '') {
                        continue;
                    }
                    if (is_numeric($trimmed)) {
                        $ids[] = (int) $trimmed;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Process a batch of specific order IDs in chunks to handle large volumes.
     */
    protected function processBatchOrders(array $orderIds, Apps $app, bool $dryRun, int $batchSize): void
    {
        $totalOrders = count($orderIds);
        $chunks = array_chunk($orderIds, $batchSize);
        $totalBatches = count($chunks);

        $progressBar = $this->output->createProgressBar($totalOrders);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
        $progressBar->setMessage('Starting...');
        $progressBar->start();

        foreach ($chunks as $batchIndex => $chunk) {
            $batchNum = $batchIndex + 1;
            $progressBar->setMessage("Batch {$batchNum}/{$totalBatches}");

            $orders = Order::whereIn('id', $chunk)
                ->where('apps_id', $app->id)
                ->get()
                ->keyBy('id');

            // Report missing orders
            $foundIds = $orders->pluck('id')->toArray();
            $missingIds = array_diff($chunk, $foundIds);
            foreach ($missingIds as $missingId) {
                $this->errorOrders[] = $missingId;
                $this->errorCount++;
            }

            foreach ($orders as $order) {
                $progressBar->setMessage("Order #{$order->id} (Batch {$batchNum}/{$totalBatches})");
                $this->processOrder($order, $app, $dryRun, true);
                $progressBar->advance();
            }

            // Advance progress for missing orders too
            $progressBar->advance(count($missingIds));

            // Free memory between batches
            unset($orders);
        }

        $progressBar->setMessage('Complete!');
        $progressBar->finish();
        $this->line('');
        $this->line('');

        if (! empty($this->errorOrders)) {
            $this->warn('Orders not found in App ' . $app->id . ': ' . implode(', ', $this->errorOrders));
            $this->line('');
        }
    }

    /**
     * Process all orders with pending insurance data using chunked queries.
     */
    protected function processAllPendingOrders(Apps $app, bool $dryRun, int $batchSize = 50): void
    {
        $this->info("Searching for orders with pending insurance data in App {$app->id}...");
        $this->line('');

        $query = Order::where('apps_id', $app->id)
            ->where('is_deleted', false)
            ->where(function ($q) {
                $q->whereRaw("JSON_EXTRACT(metadata, '$.new_data.data.insurancePendingData') IS NOT NULL")
                    ->orWhereRaw("JSON_EXTRACT(metadata, '$.insurancePendingData') IS NOT NULL")
                    ->orWhereRaw("JSON_EXTRACT(metadata, '$.new_data.data.esims') IS NOT NULL")
                    ->orWhereRaw("JSON_EXTRACT(metadata, '$.esims') IS NOT NULL");
            })
            ->orderBy('id', 'desc');

        $totalOrders = $query->count();

        if ($totalOrders === 0) {
            $this->warn('No orders found with pending insurance data');

            return;
        }

        $this->info("Found {$totalOrders} orders with pending insurance data (batch size: {$batchSize})");
        $this->line('');

        $progressBar = $this->output->createProgressBar($totalOrders);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
        $progressBar->setMessage('Starting...');
        $progressBar->start();

        $query->chunk($batchSize, function ($orders) use ($app, $dryRun, $progressBar) {
            foreach ($orders as $order) {
                $progressBar->setMessage("Processing Order #{$order->id}");
                $this->processOrder($order, $app, $dryRun, true);
                $progressBar->advance();
            }
        });

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

        // Check for insurancePendingData first (try both paths)
        $insurancePendingData = $metadata['new_data']['data']['insurancePendingData']
            ?? $metadata['insurancePendingData']
            ?? [];

        // Fallback: If no insurancePendingData, try to extract from esims
        if (empty($insurancePendingData)) {
            $insurancePendingData = $this->extractInsuranceFromEsims($metadata, $silent);
        }

        if (empty($insurancePendingData)) {
            if (! $silent) {
                $this->warn("[SKIP] No insurance data found in Order #{$orderId} (checked insurancePendingData and esims)");
            }
            $this->skippedCount++;

            return;
        }

        if (! $silent) {
            $this->info("Found " . count($insurancePendingData) . " pending insurance entries (multi-eSIM order)");

            // Show summary of what will be processed
            foreach ($insurancePendingData as $idx => $pd) {
                $name = ($pd['insurance']['titular']['firstname'] ?? '') . ' ' . ($pd['insurance']['titular']['lastname'] ?? '');
                $msgId = $pd['messageId'] ?? 'N/A';
                $icc = $pd['iccid'] ?? 'N/A';
                $this->line("  - Entry {$idx}: {$name} | Message: {$msgId} | ICCID: {$icc}");
            }
            $this->line('');
        }

        // Process each pending insurance
        $orderResults = [];
        $entryIndex = 0;
        $processedCountBefore = $this->processedCount;

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
                $this->displayInsuranceInfo($insurance, $entryIndex, $messageId, $iccid);
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
                $orderResults["entry_{$entryIndex}"] = array_merge($result, [
                    'message_id' => $messageId,
                    'iccid' => $iccid,
                ]);

                if ($result['success']) {
                    $this->processedCount++;
                    if (! $silent) {
                        $voucherId = $result['voucher_id'] ?? 'N/A';
                        $this->info("  [OK] Voucher created for Message #{$messageId}: {$voucherId}");
                    }
                } else {
                    $this->errorCount++;
                    if (! $silent) {
                        $this->error("  [ERROR] Failed to create voucher for Message #{$messageId}: " . ($result['error'] ?? 'Unknown error'));
                    }
                }
            } catch (Exception $e) {
                $this->errorCount++;
                if (! $silent) {
                    $this->error("  [ERROR] Exception for Message #{$messageId}: " . $e->getMessage());
                }
                $orderResults["entry_{$entryIndex}"] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'message_id' => $messageId,
                    'iccid' => $iccid,
                ];
            }

            $entryIndex++;
        }

        // Mark order as processed if we had any successful vouchers
        $successfulVouchers = array_filter($orderResults, fn ($r) => $r['success'] ?? false);
        if (! empty($successfulVouchers) && ! $dryRun) {
            $order->set('universal_assistance_processed', true);
            $this->processedOrders[] = $orderId;

            if (! $silent) {
                $this->line('');
                $this->info("  Multi-eSIM Summary for Order #{$orderId}:");
                $this->line("    - Total eSIMs processed: " . count($successfulVouchers));
                foreach ($successfulVouchers as $key => $voucher) {
                    $vId = $voucher['voucher_id'] ?? 'N/A';
                    $mId = $voucher['message_id'] ?? 'N/A';
                    $this->line("    - {$key}: Voucher={$vId}, Message={$mId}");
                }
            }
        }

        // Track orders that would be processed during dry-run
        if ($dryRun && $this->orderHadDryRunEntries($processedCountBefore)) {
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

            // Case 1: Both dates in the past - recalculate from today using plan duration
            if ($expiration->lt($today)) {
                // If we have a duration, we can recalculate from today
                if ($intendedDuration > 0) {
                    $newExpiration = $today->copy()->addDays($intendedDuration - 1);

                    if (! $silent) {
                        $this->warn("  [WARN] Entry #{$index}: Insurance period expired, regenerating from today with {$intendedDuration} days duration");
                    }

                    return [
                        'skip' => false,
                        'adjusted' => true,
                        'activationDate' => $today->format('Y-m-d'),
                        'expirationDate' => $newExpiration->format('Y-m-d'),
                        'reason' => null,
                    ];
                }

                // No duration available, cannot recalculate
                return [
                    'skip' => true,
                    'reason' => "Insurance period already expired (ended: {$expiration->format('Y-m-d')}) and no plan duration to recalculate",
                    'adjusted' => false,
                ];
            }

            // Case 2: Start date in the past but end date still valid
            // Recalculate from today using full intended duration (not truncated to original end date)
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
    protected function displayInsuranceInfo(array $insurance, int $index, ?int $messageId = null, ?string $iccid = null): void
    {
        $titular = $insurance['titular'] ?? [];

        $this->line('');
        $this->info("  Entry #{$index} - Insurance Details:");
        if ($iccid) {
            $this->line("     ├─ ICCID: {$iccid}");
        }
        if ($messageId) {
            $this->line("     ├─ Message ID: {$messageId}");
        }
        $this->line("     ├─ Name: " . ($titular['firstname'] ?? 'N/A') . ' ' . ($titular['lastname'] ?? ''));
        $this->line("     ├─ Email: " . ($titular['email'] ?? 'N/A'));
        $this->line("     ├─ DOB: " . ($titular['dob'] ?? 'N/A'));
        $this->line("     ├─ ID: " . ($titular['idType'] ?? '') . ' ' . ($titular['idNumber'] ?? 'N/A'));
        $this->line("     ├─ Origin: " . ($titular['originCountryCode'] ?? 'N/A'));
        $this->line("     ├─ Destination: " . ($titular['destinationCountryCode'] ?? 'N/A'));
        $this->line("     ├─ Activation: " . ($titular['activationDate'] ?? 'N/A'));
        $this->line("     ├─ Expiration: " . ($titular['expirationDate'] ?? 'N/A'));

        $plan = $titular['plan'] ?? [];
        $this->line("     └─ Plan: " . ($plan['name'] ?? 'N/A') . ' (' . ($plan['duration'] ?? '?') . ' days)');

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

            // Store the voucher data in the Message (like the activity does)
            $storageResult = null;
            if ($messageId && $voucherId) {
                $storageResult = $this->storeUniversalAssistanceData($groupResult, $messageId, $insurance, $silent);
            } elseif (! $silent) {
                if (! $messageId) {
                    $this->warn("  [WARN] No message ID available - cannot save universalAssistanceData");
                }
                if (! $voucherId) {
                    $this->warn("  [WARN] No voucher ID extracted - cannot save universalAssistanceData");
                }
            }

            return [
                'success' => true,
                'voucher_id' => $voucherId,
                'result' => $groupResult,
                'group_size' => count($groupedPersonsData),
                'storage_result' => $storageResult,
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
     * Store Universal Assistance data in the Message
     * Mimics storeUniversalAssistanceData from ProcessInsuranceCartActivity
     */
    protected function storeUniversalAssistanceData(array $groupResult, int $messageId, array $originalInsurance, bool $silent = false): array
    {
        $groupVoucherResult = $groupResult['group_voucher_result'] ?? [];
        $personsInGroup = $groupResult['persons_in_group'] ?? [];

        // Extract voucher number from group result
        $voucherNumber = $groupVoucherResult['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? $groupVoucherResult['voucher_data']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? $groupVoucherResult['voucher_id']
            ?? $groupVoucherResult['voucher_data']['nro_voucher']
            ?? null;

        $errorCode = $groupVoucherResult['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorCode']
            ?? $groupVoucherResult['voucher_data']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorCode']
            ?? '00';

        $errorMsg = $groupVoucherResult['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorMsg']
            ?? $groupVoucherResult['voucher_data']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorMsg']
            ?? 'OK';

        $controlNumber = $groupVoucherResult['voucher_data']['control_number']
            ?? $groupVoucherResult['control_number']
            ?? null;

        $organization = $groupVoucherResult['voucher_data']['organization']
            ?? $groupVoucherResult['organization']
            ?? null;

        // Build holder data from the first person (titular)
        $titular = $originalInsurance['titular'] ?? [];
        $holderData = [
            'firstName' => $titular['firstname'] ?? null,
            'lastName' => $titular['lastname'] ?? null,
            'birthDate' => $titular['dob'] ?? null,
            'documentNumber' => $titular['idNumber'] ?? null,
            'documentType' => $titular['idType'] ?? null,
            'email' => $titular['email'] ?? null,
            'gender' => $titular['sex'] ?? null,
            'error_code' => $errorCode,
            'error_msg' => $errorMsg,
            'has_individual_voucher' => true,
            'nro_control_ext' => $controlNumber,
            'nro_voucher' => $voucherNumber,
            'organization' => $organization,
        ];

        // Build dependents data
        $dependentsData = [];
        $originalDependents = $originalInsurance['dependents'] ?? [];
        foreach ($originalDependents as $depIndex => $dependent) {
            $dependentsData[] = [
                'firstName' => $dependent['firstname'] ?? null,
                'lastName' => $dependent['lastname'] ?? null,
                'birthDate' => $dependent['dob'] ?? null,
                'documentNumber' => $dependent['idNumber'] ?? null,
                'documentType' => $dependent['idType'] ?? null,
                'email' => $dependent['email'] ?? null,
                'gender' => $dependent['sex'] ?? null,
                'relationship' => $dependent['relationship'] ?? null,
                'error_code' => $errorCode,
                'error_msg' => $errorMsg,
                'has_individual_voucher' => true,
                'nro_control_ext' => $controlNumber,
                'nro_voucher' => $voucherNumber, // Same voucher for family group
                'organization' => $organization,
            ];
        }

        $universalAssistanceData = [
            'holder' => $holderData,
            'dependents' => $dependentsData,
        ];

        // Get the message and update its message content
        $saved = false;
        try {
            $message = Message::find($messageId);
            if (! $message) {
                if (! $silent) {
                    $this->warn("  [WARN] Message ID {$messageId} not found - could not save universalAssistanceData");
                }

                return [
                    'saved' => false,
                    'message_id' => $messageId,
                    'data' => $universalAssistanceData,
                    'error' => 'Message not found',
                ];
            }

            $currentMessage = $message->message ?? [];
            if (! is_array($currentMessage)) {
                $currentMessage = [];
            }

            // Update universalAssistanceData
            $currentMessage['universalAssistanceData'] = $universalAssistanceData;

            $message->message = $currentMessage;
            $message->saveOrFail();
            $saved = true;

            if (! $silent) {
                $this->info("  [SAVED] Message ID {$messageId} updated with universalAssistanceData");
                $this->line("     ├─ Voucher: {$voucherNumber}");
                $this->line("     ├─ Holder: {$holderData['firstName']} {$holderData['lastName']}");
                $this->line("     ├─ Dependents: " . count($dependentsData));
                $this->line("     └─ Error: {$errorCode} - {$errorMsg}");
            }
        } catch (Exception $e) {
            if (! $silent) {
                $this->warn("  [WARN] Failed to save to Message ID {$messageId}: " . $e->getMessage());
            }

            return [
                'saved' => false,
                'message_id' => $messageId,
                'data' => $universalAssistanceData,
                'error' => $e->getMessage(),
            ];
        }

        return [
            'saved' => $saved,
            'message_id' => $messageId,
            'data' => $universalAssistanceData,
        ];
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
     * Extract insurance data from esims array as fallback
     * Maps the esims[].eSimDetails.insurance structure to insurancePendingData format
     * This handles multi-eSIM orders where each eSIM has its own insurance data
     * and message_id, allowing individual voucher creation per eSIM
     */
    protected function extractInsuranceFromEsims(array $metadata, bool $silent = false): array
    {
        // Try both paths: new_data.data.esims or direct esims
        $esims = $metadata['new_data']['data']['esims'] ?? $metadata['esims'] ?? [];

        if (empty($esims)) {
            return [];
        }

        if (! $silent) {
            $this->info("  [FALLBACK] Found " . count($esims) . " eSIMs in order, extracting insurance data...");
        }

        $insurancePendingData = [];

        foreach ($esims as $esimIndex => $esim) {
            // Get insurance from eSimDetails
            $eSimDetails = $esim['eSimDetails'] ?? $esim['data']['eSimDetails'] ?? [];
            $insurance = $eSimDetails['insurance'] ?? null;

            if (empty($insurance) || empty($insurance['titular'])) {
                if (! $silent) {
                    $this->line("    - eSIM[{$esimIndex}]: No insurance data, skipping");
                }
                continue;
            }

            // Get message_id from the esim data (can be at root level or inside data)
            $messageId = $esim['message_id'] ?? $eSimDetails['message_id'] ?? $esim['data']['message_id'] ?? null;

            // Get iccid for fallback message lookup and identification
            $iccid = $esim['data']['iccid'] ?? $esim['iccid'] ?? null;

            // Get label for display
            $label = $eSimDetails['label'] ?? $esim['label'] ?? null;

            if (! $silent) {
                $this->line("    - eSIM[{$esimIndex}]: ICCID={$iccid}, MessageID={$messageId}, Label={$label}");
            }

            $insurancePendingData[] = [
                'insurance' => $insurance,
                'messageId' => $messageId,
                'iccid' => $iccid,
                'label' => $label,
                'source' => 'esims_fallback',
                'esim_index' => $esimIndex,
            ];
        }

        return $insurancePendingData;
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
     * Check if the order had entries that were processed during dry-run.
     * In dry-run mode, entries increment processedCount but don't add to orderResults,
     * so we compare the processedCount before/after to detect if entries were dry-run processed.
     */
    protected function orderHadDryRunEntries(int $processedCountBefore): bool
    {
        return $this->processedCount > $processedCountBefore;
    }

    /**
     * Print summary
     */
    protected function printSummary(): void
    {
        $dryRun = $this->option('dry-run');

        $this->line('');
        $this->info('╔════════════════════════════════════════════════════════════════╗');
        $this->info('║                        PROCESSING SUMMARY                       ║');
        $this->info('╚════════════════════════════════════════════════════════════════╝');
        $this->line('');

        if ($dryRun) {
            $this->warn('[DRY RUN] No vouchers were actually created');
            $this->line('');
        }

        $label = $dryRun ? 'Would process' : 'Processed';
        $this->info("  {$label}: {$this->processedCount}");
        $this->warn("  Skipped:   {$this->skippedCount}");

        if ($this->errorCount > 0) {
            $this->error("  Errors:    {$this->errorCount}");
        } else {
            $this->info("  Errors:    {$this->errorCount}");
        }

        if (! empty($this->processedOrders)) {
            $this->line('');
            $orderLabel = $dryRun ? 'Orders that would be processed' : 'Orders processed';
            $this->info("  {$orderLabel} (" . count($this->processedOrders) . '): ' . implode(', ', $this->processedOrders));
        }

        if (! empty($this->errorOrders)) {
            $this->line('');
            $this->error('  Orders not found (' . count($this->errorOrders) . '): ' . implode(', ', $this->errorOrders));
        }

        $this->line('');
    }
}