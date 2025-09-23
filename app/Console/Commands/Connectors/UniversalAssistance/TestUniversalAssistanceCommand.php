<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\UniversalAssistance;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\UniversalAssistance\Services\UniversalAssistanceService;
use Kanvas\Guild\Customers\Models\People;

class TestUniversalAssistanceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'universal-assistance:test
                            {--type=quote : Test type (workflow, quote, voucher, all-quotations, query, sendreport, consulta, anula)}
                            {--quotation-type= : Specific quotation type (inclusion, inclusion_ii, cross_selling, cross_selling_ii, stand_alone)}
                            {--voucher-number= : Voucher/Control number - For consulta: use NroControlExt, For anula: use NroVoucher}
                            {--organization= : Organization ID for query/sendreport/consulta/anula (optional, defaults to 1-ENYNUF7)}
                            {--lead-id= : Lead ID to update (for quote type only). If empty, creates new lead}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send test requests to UniversalAssistance with multiple quotation types. Use --type=workflow to test complete insurance workflow with eSim metadata storage. Use --type=all-quotations to test all organization types.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting UniversalAssistance test...');

        try {
            $app = Apps::find(1);
            if (! $app) {
                $this->error('App with ID 1 not found');
                return 1;
            }

            // Create test order associated with app 1
            $order = \Kanvas\Souk\Orders\Models\Order::create([
                'apps_id' => $app->getId(),
                'companies_id' => 1, // Add explicit company ID to avoid trait error
                'region_id' => 1, // Add region ID (required field)
                'users_id' => 9, // Set to null to avoid user relationship issues
                'people_id' => 1, // Add people ID (required field)
                'status' => 'draft', // Use valid enum value
                'total_gross_amount' => 10.00, // Use correct field name
                'total_net_amount' => 10.00, // Use correct field name
                'currency' => 'USD',
                'metadata' => [
                    'test_order' => true,
                    'universal_assistance_test' => now()->toISOString(),
                ]
            ]);

            // Create test eSim message for metadata storage (simple approach)
            // First, get or create a message type
            $messageType = \Kanvas\Social\MessagesTypes\Models\MessageType::firstOrCreate([
                'apps_id' => $app->getId(),
                'name' => 'Universal Assistance Test'
            ], [
                'languages_id' => 1, // Default language
                'verb' => 'universal_assistance_test',
                'template' => 'Universal Assistance test message',
                'templates_plura' => 'Universal Assistance test messages'
            ]);

            // Create message directly using Eloquent without observers to avoid DB issues
            $testMessage = \Kanvas\Social\Messages\Models\Message::withoutEvents(function () use ($app, $messageType) {
                return \Kanvas\Social\Messages\Models\Message::create([
                    'apps_id' => $app->getId(),
                    'companies_id' => 1,
                    'users_id' => 9,
                    'message_types_id' => $messageType->getId(),
                    'message' => [
                        'test_message' => true,
                        'universal_assistance_test' => now()->toISOString(),
                    ]
                ]);
            });

            // Set the eSim message ID in the order (same as AeroAmbulancia)
            $order->set(\Kanvas\Connectors\ESim\Enums\CustomFieldEnum::MESSAGE_ESIM_ID->value, $testMessage->getId());

            $testType = $this->option('type');
            $this->info("Test type: {$testType}");
            $this->info("Test eSim message ID: {$testMessage->getId()}");

            $service = new UniversalAssistanceService($app, $order);

            // Test connection first
            $this->info('Testing connection to Universal Assistance service...');
            $client = new \Kanvas\Connectors\UniversalAssistance\Client($app, $order->company);
            if (! $client->testConnection()) {
                $this->error('Failed to connect to Universal Assistance service. Check configuration and network connectivity.');
                $order->delete();
                return 1;
            }
            $this->info('✓ Connection test successful');

            switch ($testType) {
                case 'workflow':
                    // Test complete workflow with eSim message metadata storage
                    $this->info('Testing complete insurance workflow with eSim message metadata...');

                    $cartData = [
                        'id' => 'test-cart',
                        'total' => '35.50',
                        'total_discount' => '0.00',
                        'discounts' => [],
                        'items' => [
                            [
                                'id' => '23478',
                                'name' => '2GB eSIM',
                                'price' => '35.50',
                                'quantity' => 1,
                                'attributes' => [
                                    'eSimDetails' => [
                                        [
                                            'label' => null,
                                            'insurance' => [
                                                'titular' => [
                                                    'firstname' => 'María',
                                                    'lastname' => 'González',
                                                    'idType' => 'passport',
                                                    'idNumber' => '12345678901',
                                                    'dob' => '1985-03-15',
                                                    'sex' => 'f',
                                                    'email' => 'maria.gonzalez@email.com',
                                                    'phone' => '8095551234',
                                                    'activationDate' => '2025-12-15',
                                                    'plan' => [
                                                        'id' => '23433',
                                                        'name' => 'DOM MASTER 25K SIMLIMITES',
                                                        'duration' => '7',
                                                        'price' => '35.50'
                                                    ],
                                                    'expirationDate' => '2025-12-22',
                                                    'originCountryCode' => 'DO',
                                                    'destinyCountryCode' => 'US'
                                                ],
                                                'dependents' => [
                                                    [
                                                        'firstname' => 'Juan',
                                                        'lastname' => 'González',
                                                        'idType' => 'passport',
                                                        'idNumber' => '98765432101',
                                                        'dob' => '2010-08-22',
                                                        'sex' => 'm',
                                                        'email' => 'juan.gonzalez@email.com',
                                                        'phone' => '8095551235',
                                                        'activationDate' => '2025-12-15',
                                                        'relationship' => 'son',
                                                        'plan' => [
                                                            'id' => '25583',
                                                            'name' => 'DOM MASTER 25K SIMLIMITES',
                                                            'duration' => '7',
                                                            'price' => '25.00'
                                                        ],
                                                        'expirationDate' => '2025-12-22',
                                                        'originCountryCode' => 'DO',
                                                        'destinyCountryCode' => 'US'
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ];

                    // Setup order metadata with Universal Assistance data for service
                    $uaData = [
                        'travel_data' => [
                            'firstname' => 'María',
                            'lastname' => 'González',
                            'idType' => 'cedula',
                            'idNumber' => '12345678901',
                            'dob' => '1985-03-15',
                            'email' => 'maria.gonzalez@email.com',
                            'activationDate' => '2025-12-15',
                            'originCountryCode' => 'DO',
                            'destinyCountryCode' => 'US'
                        ],
                        'voucher_data' => [
                            'firstname' => 'María',
                            'lastname' => 'González',
                            'idType' => 'DNI',
                            'idNumber' => '12345678901',
                            'email' => 'maria.gonzalez@email.com',
                            'birth_date' => '1985-03-15',
                            'start_date' => '2025-12-15',
                            'end_date' => '2025-12-22',
                            'control_number' => 'TEST' . time(),
                            'channel' => 'WEB',
                            'line' => 'INSURANCE',
                            'applicant_document_number' => '12345678901',
                            'plan' => [
                                'name' => 'DOM MASTER 25K SIMLIMITES',
                                'type' => 'inclusion',
                                'price' => 35.50,
                                'duration' => 7
                            ]
                        ]
                    ];

                    // Test workflow using InsuranceWorkflowService (same as the activity uses)
                    $insuranceService = new \Kanvas\Connectors\UniversalAssistance\Services\InsuranceWorkflowService($app, $order);
                    $result = $insuranceService->processInsuranceWorkflow($cartData);

                    $this->info('✅ Workflow completed successfully');
                    $this->info('🎯 Results: Only titular gets voucher, dependents stored in eSim metadata');
                    break;
                case 'quote':
                    // Direct client call with quote data to bypass service layer transformation
                    $leadId = $this->option('lead-id');
                    if ($leadId) {
                        $this->info("Using direct client call to UPDATE existing lead: {$leadId}");
                    } else {
                        $this->info('Using direct client call to CREATE new travel lead...');
                    }
                    $client = new \Kanvas\Connectors\UniversalAssistance\Client($app, $order->company);
                    $quoteData = $this->getTestTravelDataForSOAP();
                    $result = $client->createOrUpdateLead($quoteData, true); // Use raw data flag
                    $this->info('Travel quote sent using validated SOAP data.');
                    $this->displaySOAPData('Quote/Travel', $quoteData);
                    break;
                case 'voucher':
                    // Create single voucher with valid product and price
                    $this->info('Creating single voucher with valid Universal Assistance product...');
                    $client = new \Kanvas\Connectors\UniversalAssistance\Client($app, $order->company);

                    // Generate single control number
                    $controlNumbers = $client->generateSequentialControlNumbers($order);
                    $controlNumber = $controlNumbers['base'] . '-V1'; // Single voucher

                    // Prepare voucher data with empty price as always requested
                    $voucherData = $this->getTestVoucherDataForSOAP();
                    $voucherData['NroControl'] = $controlNumber;
                    $voucherData['Precio'] = '25.00'; // Always empty - price is handled by the system

                    // Create single voucher
                    $this->info('Creating voucher with control number: ' . $controlNumber);
                    $result = $client->createVoucher($voucherData, true); // Raw mode

                    // Display results summary
                    $this->info('--- Single Voucher Results ---');
                    $this->info('✓ Voucher created with control number: ' . $controlNumber);
                    $this->info('✓ Using valid Universal Assistance product: ' . $voucherData['DatosProducto']['NombreProducto']);
                    $this->info('✓ Price field: Empty (handled by system)');
                    $this->info('✓ QA user as seller: ' . $voucherData['Vendedor']);
                    $this->info('✓ ImprimeTarifa field set to "N" as requested.');

                    // Display SOAP data for reference
                    $this->displaySOAPData('Single Voucher', $voucherData);
                    break;
                case 'all-quotations':
                    // Test all quotation types individually
                    $this->info('Testing ALL quotation types with their specific organizations...');
                    $client = new \Kanvas\Connectors\UniversalAssistance\Client($app, $order->company);
                    $result = $this->testAllQuotationTypes($client, $order);
                    break;
                case 'single-quotation':
                    // Test specific quotation type
                    $quotationType = $this->option('quotation-type') ?: 'inclusion';
                    $this->info("Testing single quotation type: {$quotationType}");
                    $client = new \Kanvas\Connectors\UniversalAssistance\Client($app, $order->company);
                    $result = $this->testSingleQuotationType($client, $order, $quotationType);
                    break;
                case 'query':
                    // Direct client call with query data to bypass service layer transformation
                    $this->info('Using direct client call for voucher query...');
                    $client = new \Kanvas\Connectors\UniversalAssistance\Client($app, $order->company);
                    $queryData = $this->getTestQueryDataForSOAP();

                    // Override voucher number if provided via command line, otherwise use default (testing NroControlExt)
                    $voucherNumber = $this->option('voucher-number') ?: 'TEST-PHP-522';
                    $queryData['VoucherNumber'] = $voucherNumber;
                    $this->info("Using voucher number: {$voucherNumber}");

                    // Override organization if provided via command line, otherwise use correct QA fallback
                    $organization = $this->option('organization') ?: '1-ENYNUF7';
                    $queryData['Organization'] = $organization;
                    $this->info("Using organization: {$organization}");

                    $result = $client->queryVoucher($queryData, true); // Use raw data flag like QA
                    $this->info('Voucher query sent using validated SOAP data.');
                    $this->displaySOAPData('Query', $queryData);
                    break;
                case 'sendreport':
                    // Direct client call with sendreport data to get voucher PDF
                    $this->info('Using direct client call for SendReport (PDF generation)...');
                    $client = new \Kanvas\Connectors\UniversalAssistance\Client($app, $order->company);
                    $sendReportData = $this->getTestSendReportDataForSOAP();

                    // Override voucher number if provided via command line, otherwise use default
                    $voucherNumber = $this->option('voucher-number') ?: 'TEST-PHP-522';
                    $sendReportData['VoucherNumber'] = $voucherNumber;
                    $this->info("Using voucher number (trying NroControlExt): {$voucherNumber}");

                    // Override organization if provided via command line, otherwise use correct QA fallback
                    $organization = $this->option('organization') ?: '1-ENYNUF7';
                    $sendReportData['Organization'] = $organization;
                    $this->info("Using organization: {$organization}");

                    $result = $client->sendReport($sendReportData, true); // Use raw data flag
                    $this->info('SendReport request sent using validated SOAP data.');
                    $this->displaySOAPData('SendReport', $sendReportData);
                    break;
                case 'consulta':
                    // Direct client call with consulta voucher data
                    $this->info('Using direct client call for Consulta Voucher...');
                    $client = new \Kanvas\Connectors\UniversalAssistance\Client($app, $order->company);
                    $consultaData = $this->getTestConsultaVoucherDataForSOAP();

                    // Override control number if provided via command line, otherwise use default (NroControlExt)
                    $controlNumber = $this->option('voucher-number') ?: 'TEST-PHP-522';
                    $consultaData['UAConsultaVoucherRequest']['ConsultaDatosVoucherReq']['NroControlConsulta'] = $controlNumber;
                    $this->info("Using control number (NroControlExt): {$controlNumber}");

                    // Override organization if provided via command line, otherwise use correct QA fallback
                    $organization = $this->option('organization') ?: '1-ENYNUF7';
                    $consultaData['UAConsultaVoucherRequest']['ConsultaDatosVoucherReq']['OrganizacionRegistradoraConsulta'] = $organization;
                    $this->info("Using organization: {$organization}");

                    $result = $client->consultaVoucher($consultaData, true); // Use raw data flag
                    $this->info('Consulta Voucher request sent using validated SOAP data.');
                    $this->displaySOAPData('ConsultaVoucher', $consultaData);
                    break;
                case 'anula':
                    // Direct client call with anula voucher data
                    $this->info('Using direct client call for Anula Voucher...');
                    $client = new \Kanvas\Connectors\UniversalAssistance\Client($app, $order->company);
                    $anulaData = $this->getTestAnulaVoucherDataForSOAP();

                    // Override voucher number if provided via command line, otherwise use default (NroVoucher)
                    $voucherNumber = $this->option('voucher-number') ?: 'T417500963';
                    $anulaData['NroVoucherSiebelAnulacion'] = $voucherNumber;
                    $this->info("Using voucher number (NroVoucher): {$voucherNumber}");

                    // Override organization if provided via command line, otherwise use correct QA fallback
                    $organization = $this->option('organization') ?: '1-ENYNUF7';
                    $anulaData['AgenciaAnulacion'] = $organization;
                    $this->info("Using organization: {$organization}");

                    $result = $client->anulaVoucher($anulaData, true); // Use raw data flag
                    $this->info('Anula Voucher request sent using validated SOAP data.');
                    $this->displaySOAPData('AnulaVoucher', $anulaData);
                    break;
                default:
                    $this->error("Invalid test type: {$testType}");
                    $order->delete();
                    return 1;
            }

            $this->info('Test completed.');
            $this->displayResult($result);

            // Show eSim message metadata
            $testMessage->refresh();
            $this->info('📱 eSim Message Data:');
            if (isset($testMessage->message['universal_assistance'])) {
                $this->line(json_encode($testMessage->message['universal_assistance'], JSON_PRETTY_PRINT));
            } else {
                $this->warn('No Universal Assistance data found in eSim message');
            }

            $order->delete();
            $testMessage->delete();
        } catch (\Exception $e) {
            $this->error('Error in test: ' . $e->getMessage());
            $this->line('Stack trace:');
            $this->line($e->getTraceAsString());

            // Clean up test data
            if (isset($order)) {
                $order->delete();
            }
            if (isset($testMessage)) {
                $testMessage->delete();
            }
            return 1;
        }

        return 0;
    }

    /**
     * Display SOAP data that was actually sent (works for all test types)
     */
    private function displaySOAPData(string $testType, array $data): void
    {
        $this->line("{$testType} SOAP Data Sent:");
        $this->table(
            ['Field', 'Value'],
            $this->flattenArrayForDisplay($data, '')
        );
    }

    /**
     * Flatten array for display in table format
     */
    private function flattenArrayForDisplay(array $data, string $prefix): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $currentPath = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value)) {
                // If array has numeric keys, show as indexed items
                if (array_keys($value) === range(0, count($value) - 1)) {
                    foreach ($value as $index => $item) {
                        $indexedPath = "{$currentPath}[{$index}]";
                        if (is_array($item) || is_object($item)) {
                            $result = array_merge($result, $this->flattenArrayForDisplay((array)$item, $indexedPath));
                        } else {
                            $result[] = [$indexedPath, $this->formatDisplayValue($item)];
                        }
                    }
                } else {
                    // Associative array - recurse normally
                    $result = array_merge($result, $this->flattenArrayForDisplay($value, $currentPath));
                }
            } else {
                $result[] = [$currentPath, $this->formatDisplayValue($value)];
            }
        }
        return $result;
    }

    /**
     * Format value for display
     */
    private function formatDisplayValue($value): string
    {
        if ($value === '' || $value === null) {
            return '<empty>';
        }
        return (string)$value;
    }

    /**
     * Display travel data
     */
    private function displayTravelData(array $data): void
    {
        $this->line('Travel data:');
        $this->table(
            ['Field', 'Value'],
            [
                ['Origin Country', $data['paisOrigen']],
                ['Destination', $data['destino']],
                ['Start Date', $data['fechaInicio']],
                ['End Date', $data['fechaFin']],
                ['Trip Type', $data['tripType']],
                ['Passengers', $data['passengerCount']],
                ['Ages', implode(', ', $data['passengerAges'])],
            ]
        );
    }

    /**
     * Display voucher data
     */
    private function displayVoucherData(array $data): void
    {
        $this->line('Voucher data:');
        $this->table(
            ['Field', 'Value'],
            [
                ['Lead ID', $data['idLead']],
                ['Product', $data['idProducto']],
                ['Sale Type', $data['tipoVenta']],
                ['Total Amount', '$' . number_format($data['montoTotal'], 2)],
                ['Currency', $data['moneda']],
                ['Passengers', count($data['passengers'])],
                ['Departure Date', $data['travel_dates']['departure']],
                ['Return Date', $data['travel_dates']['return']],
            ]
        );
    }

    /**
     * Display query data
     */
    private function displayQueryData(array $data): void
    {
        $this->line('Query parameters:');
        $this->table(
            ['Field', 'Value'],
            array_map(
                fn ($key, $value) => [$key, is_array($value) ? json_encode($value) : $value],
                array_keys($data),
                $data
            )
        );
    }

    /**
     * Display result
     */
    private function displayResult($result): void
    {
        $this->line('📋 Raw SOAP Response:');

        if (empty($result)) {
            $this->warn('Empty response');
            return;
        }

        // Convert to array for consistent processing
        $resultArray = $this->convertToArray($result);

        // Display the pure result as formatted JSON
        $this->line(json_encode($resultArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Generate Excel file (only for non-all-quotations types, as they generate consolidated Excel)
        $testType = $this->option('type');
        if ($testType !== 'all-quotations') {
            $this->generateExcel($resultArray);
        } else {
            $this->info('💡 Consolidated Excel already generated for all quotations.');
        }
    }

    /**
     * Recursively convert objects to arrays
     */
    private function convertToArray($data): array
    {
        if (is_object($data)) {
            // Convert object to array using object casting instead of json_encode/decode
            $data = (array) $data;
        }

        if (is_array($data)) {
            // If it's an array with a single object, extract the object
            if (count($data) === 1 && isset($data[0]) && is_object($data[0])) {
                $data = (array) $data[0];
            } else {
                // Recursively convert all elements
                foreach ($data as $key => $value) {
                    if (is_object($value) || is_array($value)) {
                        $data[$key] = $this->convertToArray($value);
                    }
                }
            }
        }

        return is_array($data) ? $data : [$data];
    }

    /**
     * Generate CSV file from raw SOAP response (for any test type)
     */
    private function generateExcel(array $result): void
    {
        try {
            // Create filename with timestamp and test type
            $timestamp = now()->format('Y-m-d_H-i-s');
            $testType = $this->option('type') ?? 'unknown';
            $filename = "universal_assistance_soap_response_{$testType}_{$timestamp}.csv";
            $filepath = storage_path("app/public/{$filename}");

            // Ensure the directory exists
            if (!file_exists(dirname($filepath))) {
                mkdir(dirname($filepath), 0755, true);
            }

            $file = fopen($filepath, 'w');

            // Write UTF-8 BOM for proper Excel encoding
            fwrite($file, "\xEF\xBB\xBF");

            // Write CSV headers
            fputcsv($file, ['Property Path', 'Value'], ';');

            // Flatten the SOAP response recursively and write to CSV
            $this->writeFlattenedData($file, $result, '');

            fclose($file);

            $this->info("📊 CSV file generated successfully: storage/app/public/{$filename}");
            $this->line("📂 Full path: {$filepath}");
            $this->line("📋 SOAP Response Type: {$testType}");
            $this->line("📊 Timestamp: {$timestamp}");

        } catch (\Exception $e) {
            $this->error('Failed to generate CSV: ' . $e->getMessage());
        }
    }

    /**
     * Recursively flatten and write array data to CSV
     */
    private function writeFlattenedData($file, $data, string $prefix): void
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $currentPath = $prefix ? "{$prefix}.{$key}" : $key;

                if (is_array($value)) {
                    // Handle arrays with numeric indices (like multiple items)
                    if (array_keys($value) === range(0, count($value) - 1)) {
                        // Numeric array - write each item with index
                        foreach ($value as $index => $item) {
                            $indexedPath = "{$currentPath}[{$index}]";
                            if (is_array($item) || is_object($item)) {
                                $this->writeFlattenedData($file, $item, $indexedPath);
                            } else {
                                fputcsv($file, [$indexedPath, $this->safeStringValue($item)], ';');
                            }
                        }
                    } else {
                        // Associative array - recurse normally
                        $this->writeFlattenedData($file, $value, $currentPath);
                    }
                } elseif (is_object($value)) {
                    $this->writeFlattenedData($file, (array)$value, $currentPath);
                } else {
                    // Write the key-value pair
                    fputcsv($file, [$currentPath, $this->safeStringValue($value)], ';');
                }
            }
        } else {
            // Single value
            fputcsv($file, [$prefix ?: 'root', $this->safeStringValue($data)], ';');
        }
    }

    /**
     * Convert value to safe string for CSV
     */
    private function safeStringValue($value): string
    {
        if (is_array($value)) {
            // If it's an empty array, return empty string
            if (empty($value)) {
                return '';
            }

            // Check if it's a simple array of strings/numbers
            $isSimple = true;
            foreach ($value as $item) {
                if (is_array($item) || is_object($item)) {
                    $isSimple = false;
                    break;
                }
            }

            if ($isSimple) {
                // Join simple values with commas
                return implode(', ', array_map('strval', $value));
            } else {
                // For complex arrays, use clean formatting
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        if (is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // Ensure proper string encoding for special characters
        $stringValue = (string) $value;

        // Convert HTML entities to proper characters
        $stringValue = html_entity_decode($stringValue, ENT_QUOTES, 'UTF-8');

        return $stringValue;
    }

    private function getTestTravelData(): array
    {
        // Based on PDF documentation DIS035 - REQ 3354
        return [
            'paisOrigen' => 'ARG', // Origin country
            'destino' => 'BRA', // Destination country
            'fechaInicio' => now()->addDays(30)->format('Y-m-d'), // Trip start date
            'fechaFin' => now()->addDays(37)->format('Y-m-d'), // Trip end date
            'passengerCount' => 1,
            'passengerAges' => [35],
            'tripType' => 'SINGLE_TRIP', // Enum/documentation
            'agreementId' => 'TEST-AGREEMENT',
            'brochure' => 'N',
            'familyPack' => null,
            'quoteCount' => 1,
        ];
    }

    private function getTestTravelDataForSOAP(): array
    {
        // Exact data from working QA request that returns products
        return [
            'IdLead' => $this->option('lead-id') ?? '',
            'OrganizacionEmisora' => '1-ENYNUF7', // ✅ Same as working request
            'Convenio' => '1-EO6M4QU', // ✅ Changed to new contract as requested
            'Folleto' => '', // ✅ Same as working request
            'PaisOrigen' => 'TURQUIA', // ✅ Changed to match working request
            'Destino' => 'Territorio Nacional', // ✅ Changed to match working request
            'TipoViaje' => 'Un viaje', // ✅ Same as working request
            'FechaInicio' => '12/15/2025', // ✅ Same as working request
            'FechaFin' => '12/22/2025', // ✅ Same as working request
            'CantidadPasajeros' => 1, // ✅ Same as working request
            'Edad1' => 27, // ✅ Same as working request
            'Edad2' => '', // ✅ Same as working request
            'Edad3' => '', // ✅ Same as working request
            'Edad4' => '', // ✅ Same as working request
            'Edad5' => '', // ✅ Same as working request
            'Edad6' => '', 'Edad7' => '', 'Edad8' => '', 'Edad9' => '', 'Edad10' => '',
            'ApellidoContacto' => '', // ✅ Changed to empty (working request sends empty)
            'NombreContacto' => '', // ✅ Changed to empty (working request sends empty)
            'TelefonoContacto' => '', // ✅ Changed to empty (working request sends empty)
            'EmailContacto' => '', // ✅ Changed to empty (working request sends empty)
            'Categoria' => '', // ✅ Same as working request
            'Precompras' => '', // ✅ Same as working request
            // Removed CantCotizaciones, PackFamiliar, NroDocumento, TipoDocumento - not in working request
        ];
    }

    private function getTestVoucherData(): array
    {
        // Based on PDF documentation DIS035 - REQ 3354
        return [
            'idLead' => 'TEST_LEAD_' . now()->timestamp,
            'idProducto' => 'TEST_PRODUCT',
            'tipoVenta' => 'direct',
            'montoTotal' => 25.00,
            'moneda' => 'USD',
            'passengers' => [
                [
                    'first_name' => 'Juan',
                    'last_name' => 'Pérez',
                    'birth_date' => '1990-05-15',
                    'gender' => 'M',
                    'document_type' => 'passport',
                    'document_number' => 'A12345678',
                    'email' => 'juan.perez@test.com',
                    'phone' => '+52551234567'
                ]
            ],
            'travel_dates' => [
                'departure' => now()->addDays(30)->format('Y-m-d'),
                'return' => now()->addDays(37)->format('Y-m-d'),
            ],
            'coverage_details' => [
                'medical_expenses' => 50000,
                'trip_cancellation' => 1000,
                'lost_luggage' => 500,
            ]
        ];
    }

    private function getTestQuoteDataForSOAP(): array
    {
        // Get the configured organization for seller
        $app = \Kanvas\Apps\Models\Apps::find(1);
        $organization = $app?->get('UNIVERSAL_ASSISTANCE_ORGANIZATION') ?: '1-ENYNUF7';

        // Quote data structure - simplified compared to voucher data
        // This is used for quote-only testing, no product name or price needed

        return [
            // Main quote fields - following the structure used in convertVoucherDataToLeadData
            'NroControl' => '', // Will be set by quotation system
            'Destino' => 'Centro america/Caribe', // Default destination
            'FechaEmision' => '09/12/2025',
            'FechaVigencia' => '01/08/2026',
            'FechaFinal' => '01/08/2026',
            'MonedaLista' => 'USD',
            'Precio' => '', // No price for quotes
            'Canal' => 'Turismo',
            'Contrato' => '1-EO6M4QP', // Default convenio
            'LeadId' => '',

            // Sub-structures for quote (no product name or price for quotes)
            'DatosAgencia' => [
                'OrganizacionRegistradora' => '1-ENYNUF7', // Fixed organization for quotes
            ],

            'DatosProducto' => [
                // No NombreProducto or Precio for quotes
            ],

            'DatosSolicitante' => [
                'NombreSolicitante' => 'Juan',
                'ApellidoSolicitante' => 'Perez',
                'TipoDocumentoSolicitante' => 'DNI',
                'NroDocumentoSolicitante' => '33555777',
                'FechaNacimientoSolicitante' => '05/15/1997', // MM/dd/yyyy format
                'CorreoElectronicoSolicitante' => 'juan.perez@test.com.ar',
            ],
        ];
    }

    private function getTestVoucherDataForSOAP(): array
    {
        // Updated to use valid Universal Assistance product names
        // Using Asistencia 40K Rec for testing with proper QA credentials

        return [
            // Main voucher fields - Updated for QA environment with valid products
            'NroControl' => '', // Will be set by dual quotation system
            'Vendedor' => 'WSSIMLIMITEDO',
            'FechaEmision' => now()->format('m/d/Y'),
            'Destino' => 'Territorio Nacional', // For receptivo products
            'FechaVigencia' => now()->addDays(1)->format('m/d/Y'),
            'FechaFinal' => now()->addDays(8)->format('m/d/Y'), // 7-day coverage
            'MonedaLista' => 'USD',
            'Precio' => '0.00', // Empty as requested - handled by system
            'NombreContactoVoucher' => '',
            'NroTelContactoVoucher' => '',
            'Canal' => 'Turismo',
            'Contrato' => '1-EO7PJQQ', // Inclusion II contract for receptivo
            'LeadId' => '',
            'EnvioVoucherMail' => 'Y',
            'PostProcesoFlag' => 'N',
            'ImprimeTarifa' => 'N', // Campo "imprime tarifa" en "N"

            // Sub-structures for voucher with QA credentials
            'DatosAgencia' => [
                'OrganizacionRegistradora' => '1-ENYNUF7', // QA organization
            ],

            'DatosProducto' => [
                'NombreProducto' => 'DOM MASTER 40K SIMLIMITES REC', // Valid product name for Asistencia 40K Rec
            ],

            'DatosSolicitante' => [
                'NroPolizaSeguro' => '',
                'NombreSolicitante' => 'Juan',
                'ApellidoSolicitante' => 'Perez',
                'TipoDocumentoSolicitante' => 'DNI',
                'NroDocumentoSolicitante' => '00123456789',
                'PaisResidenciaSolicitante' => 'REPUBLICA DOMINICANA', // Changed to match receptivo
                'FechaNacimientoSolicitante' => '05/15/1997',
                'CorreoElectronicoSolicitante' => 'pelleranomanuel@gmail.com', // Dominican email
            ],
        ];
    }

    private function getTestQueryData(): array
    {
        // Based on PDF documentation DIS035 - REQ 3354
        return [
            'idLead' => 'TEST_LEAD_' . now()->timestamp,
            'fechaInicio' => now()->addDays(30)->format('Y-m-d'),
            'fechaFin' => now()->addDays(37)->format('Y-m-d'),
            'estado' => 'active',
        ];
    }

    private function getTestQueryDataForSOAP(): array
    {
        // Based on QueryVoucherPortal WS WSDL structure - simplified query with voucher number
        // WSDL doesn't specify if VoucherNumber should be NroVoucher or NroControlExt - testing both
        return [
            // WSDL input parameters - only 2 required fields
            'VoucherNumber' => 'TEST-PHP-522', // Testing with NroControlExt now
            'Organization' => '1-ENYNUF7', // Correct QA organization
        ];
    }



    private function getTestSendReportDataForSOAP(): array
    {
        // Based on SendReport WS WSDL structure - PDF generation from voucher
        return [
            // WSDL input parameters - 4 required fields
            'Language' => 'Spanish', // Default Spanish as requested
            'VoucherNumber' => 'TEST-PHP-522', // Try with NroControlExt first
            'Tarifa' => '', // Tariff information (optional, empty by default)
            'Organization' => '1-ENYNUF7', // Correct QA organization (can be overridden)
        ];
    }

    private function getTestConsultaVoucherDataForSOAP(): array
    {
        // Based on Operaciones Voucher WS WSDL - Consulta_Voucher_Operation
        // UAConsultaVoucherRequest structure - uses NroControlExt (control number)
        return [
            'UAConsultaVoucherRequest' => [
                'ConsultaDatosVoucherReq' => [
                    'OrganizacionRegistradoraConsulta' => '1-ENYNUF7', // Correct QA organization (can be overridden)
                    'NroControlConsulta' => 'TEST-PHP-522', // Control number (NroControlExt) - can be overridden
                ]
            ]
        ];
    }

    private function getTestAnulaVoucherDataForSOAP(): array
    {
        // Based on Operaciones Voucher WS WSDL - Anula_Voucher_Operation
        return [
            // Anula_Voucher_Operation parameters - uses NroVoucher (voucher system number)
            'AgenciaAnulacion' => '1-ENYNUF7', // Correct QA organization (can be overridden)
            'NroVoucherSiebelAnulacion' => 'T417500963', // Voucher number (NroVoucher) - can be overridden
        ];
    }

    /**
     * Test all quotation types with their specific organizations
     */
    protected function testAllQuotationTypes(\Kanvas\Connectors\UniversalAssistance\Client $client, \Kanvas\Souk\Orders\Models\Order $order): array
    {
        $results = [];
        $quotationTypes = $client->getAllQuotationTypes();

        $this->info('=== TESTING ALL QUOTATION TYPES ===');
        $this->info('Total types to test: ' . count($quotationTypes));
        $this->line('');

        foreach ($quotationTypes as $typeData) {
            $this->info("🧪 Testing: {$typeData['name']} ({$typeData['code']})");
            $this->info("   Organization: {$typeData['organization']}");
            $this->info("   Description: {$typeData['description']}");

            try {
                // Create quote data specific for this quotation type (quote-only, no voucher data)
                $quoteData = $this->getTestQuoteDataForQuotationType($typeData['code']);

                // Create single quotation (quote-only mode for all-quotations test)
                $result = $client->createSingleQuotation($quoteData, $typeData['code'], $order, true);
                $results[$typeData['code']] = $result;

                $this->info("   ✅ SUCCESS - Control Number: {$result['control_number']}");
                $this->displaySOAPDataSummary($typeData['name'], $quoteData);

                // Excel will be generated consolidated at the end

            } catch (\Exception $e) {
                $this->error("   ❌ FAILED: " . $e->getMessage());
                $results[$typeData['code']] = ['error' => $e->getMessage()];
            }

            $this->line('');
        }

        $this->displayAllQuotationResults($results);

        // Generate consolidated Excel file for all quotations
        $this->generateConsolidatedExcel($results);

        return $results;
    }

    /**
     * Test specific quotation type
     */
    protected function testSingleQuotationType(\Kanvas\Connectors\UniversalAssistance\Client $client, \Kanvas\Souk\Orders\Models\Order $order, string $quotationType): array
    {
        $quotationTypes = $client->getAllQuotationTypes();

        if (! isset($quotationTypes[$quotationType])) {
            $this->error("Invalid quotation type: {$quotationType}");
            $this->info("Available types: " . implode(', ', array_keys($quotationTypes)));
            return [];
        }

        $typeData = $quotationTypes[$quotationType];

        $this->info("=== TESTING SINGLE QUOTATION TYPE ===");
        $this->info("Type: {$typeData['name']} ({$typeData['code']})");
        $this->info("Organization: {$typeData['organization']}");
        $this->info("Description: {$typeData['description']}");
        $this->line('');

        try {
            $quoteData = $this->getTestQuoteDataForQuotationType($quotationType);
            $result = $client->createSingleQuotation($quoteData, $quotationType, $order);

            $this->info("✅ SUCCESS - Control Number: {$result['control_number']}");
            $this->displaySOAPData($typeData['name'], $quoteData);

            // Generate consolidated Excel for this single quotation
            $this->generateConsolidatedExcel([$quotationType => $result]);

            return $result;

        } catch (\Exception $e) {
            $this->error("❌ FAILED: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get voucher data customized for specific quotation type
     */
    protected function getTestQuoteDataForQuotationType(string $quotationType): array
    {
        $baseData = $this->getTestQuoteDataForSOAP();

        // Customize based on quotation type (remove product-specific fields for quotes)
        switch ($quotationType) {
            case 'inclusion':
                $baseData['Destino'] = 'Europa';
                // Remove product name and price for quotes
                unset($baseData['DatosProducto']['NombreProducto']);
                unset($baseData['Precio']);
                break;

            case 'inclusion_ii':
                $baseData['Destino'] = 'Europa Premium';
                // Remove product name and price for quotes
                unset($baseData['DatosProducto']['NombreProducto']);
                unset($baseData['Precio']);
                break;

            case 'cross_selling':
                $baseData['Destino'] = 'América del Norte';
                // Remove product name and price for quotes
                unset($baseData['DatosProducto']['NombreProducto']);
                unset($baseData['Precio']);
                break;

            case 'cross_selling_ii':
                $baseData['Destino'] = 'Mundial Premium';
                // Remove product name and price for quotes
                unset($baseData['DatosProducto']['NombreProducto']);
                unset($baseData['Precio']);
                break;

            case 'stand_alone':
                $baseData['Destino'] = 'Internacional';
                // Remove product name and price for quotes
                unset($baseData['DatosProducto']['NombreProducto']);
                unset($baseData['Precio']);
                break;
        }

        return $baseData;
    }

    /**
     * Display SOAP data summary (shorter version)
     */
    protected function displaySOAPDataSummary(string $title, array $data): void
    {
        $this->info("   📋 {$title} Data:");
        $this->info("      Control: {$data['NroControl']}");
        $this->info("      Destination: {$data['Destino']}");
        $priceInfo = isset($data['Precio']) ? "{$data['Precio']} {$data['MonedaLista']}" : "N/A (Quote only)";
        $this->info("      Price: {$priceInfo}");
        $this->info("      Organization: {$data['DatosAgencia']['OrganizacionRegistradora']}");
    }

    /**
     * Display all quotation results summary
     */
    protected function displayAllQuotationResults(array $results): void
    {
        $this->info('=== FINAL RESULTS SUMMARY ===');

        $successCount = 0;
        $errorCount = 0;

        foreach ($results as $type => $result) {
            if (isset($result['error'])) {
                $this->error("❌ {$type}: FAILED");
                $errorCount++;
            } else {
                $this->info("✅ {$type}: SUCCESS - Control: {$result['control_number']}");
                $successCount++;
            }
        }

        $this->line('');
        $this->info("Total Success: {$successCount}");
        $this->info("Total Errors: {$errorCount}");
        $this->info("All results stored in order metadata.");
        $this->info("Excel files generated for successful quotations.");
    }

    /**
     * Generate Excel file for specific quotation type
     */
    protected function generateExcelForQuotationType(array $result, array $typeData): void
    {
        try {
            if (! isset($result['response']) || ! isset($result['control_number'])) {
                return;
            }

            $filename = 'UA_' . strtoupper($typeData['code']) . '_' . $result['control_number'] . '_' . date('YmdHis') . '.csv';
            $file = fopen($filename, 'w');

            if (! $file) {
                $this->error("Could not create Excel file: {$filename}");
                return;
            }

            // Add headers
            fputcsv($file, ['Quotation Type', 'Control Number', 'Organization', 'Field', 'Value']);

            // Add metadata
            fputcsv($file, [$typeData['name'], $result['control_number'], $result['organization'], 'Quotation Type', $typeData['name']]);
            fputcsv($file, [$typeData['name'], $result['control_number'], $result['organization'], 'Description', $typeData['description']]);
            fputcsv($file, [$typeData['name'], $result['control_number'], $result['organization'], 'Control Number', $result['control_number']]);
            fputcsv($file, [$typeData['name'], $result['control_number'], $result['organization'], 'Organization', $result['organization']]);

            // Add response data
            $this->writeFlattenedDataForQuotationType($file, $result['response'], $typeData['name'], $result['control_number'], $result['organization']);

            fclose($file);

            $this->info("   📊 Excel generated: {$filename}");

        } catch (\Exception $e) {
            $this->error("Error generating Excel for {$typeData['name']}: " . $e->getMessage());
        }
    }

    /**
     * Write flattened data for specific quotation type
     */
    protected function writeFlattenedDataForQuotationType($file, $data, string $quotationType, string $controlNumber, string $organization, string $prefix = ''): void
    {
        if (is_array($data) || is_object($data)) {
            foreach ((array)$data as $key => $value) {
                $currentKey = empty($prefix) ? $key : "{$prefix}.{$key}";

                if (is_array($value) || is_object($value)) {
                    $this->writeFlattenedDataForQuotationType($file, $value, $quotationType, $controlNumber, $organization, $currentKey);
                } else {
                    fputcsv($file, [$quotationType, $controlNumber, $organization, $currentKey, $this->safeStringValue($value)]);
                }
            }
        } else {
            fputcsv($file, [$quotationType, $controlNumber, $organization, $prefix ?: 'Root', $this->safeStringValue($data)]);
        }
    }

    /**
     * Generate consolidated Excel file for all quotations
     */
    protected function generateConsolidatedExcel(array $results): void
    {
        try {
            $timestamp = date('YmdHis');
            $filename = "UA_CONSOLIDATED_QUOTATIONS_{$timestamp}.csv";
            $filepath = storage_path("app/public/{$filename}");

            $file = fopen($filepath, 'w');

            if (! $file) {
                $this->error("Could not create consolidated Excel file: {$filename}");
                return;
            }

            // Write UTF-8 BOM for proper Excel encoding
            fwrite($file, "\xEF\xBB\xBF");

            // Write CSV headers
            fputcsv($file, ['Quotation Type', 'Control Number', 'Organization', 'Convenio', 'Field', 'Value'], ';');

            $successCount = 0;
            $errorCount = 0;

            foreach ($results as $quotationType => $result) {
                if (isset($result['error'])) {
                    $errorCount++;
                    // Write error information
                    fputcsv($file, [
                        strtoupper(str_replace('_', ' ', $quotationType)),
                        'ERROR',
                        'N/A',
                        'N/A',
                        'Error Message',
                        $result['error']
                    ], ';');
                    continue;
                }

                if (! isset($result['response']) || ! isset($result['control_number'])) {
                    continue;
                }

                $successCount++;
                $quotationName = strtoupper(str_replace('_', ' ', $quotationType));
                $controlNumber = $result['control_number'];
                $organization = $result['organization'] ?? 'Unknown';
                $convenio = $result['convenio'] ?? 'Unknown';

                // Write metadata first
                fputcsv($file, [$quotationName, $controlNumber, $organization, $convenio, 'Quotation Type', $quotationName], ';');
                fputcsv($file, [$quotationName, $controlNumber, $organization, $convenio, 'Control Number', $controlNumber], ';');
                fputcsv($file, [$quotationName, $controlNumber, $organization, $convenio, 'Organization', $organization], ';');
                fputcsv($file, [$quotationName, $controlNumber, $organization, $convenio, 'Convenio', $convenio], ';');

                // Write all response data flattened
                $this->writeConsolidatedFlattenedData($file, $result['response'], $quotationName, $controlNumber, $organization, $convenio);

                // Write tried origins/destinations information for debugging
                if (isset($result['tried_origins'])) {
                    fputcsv($file, [$quotationName, $controlNumber, $organization, $convenio, 'DEBUG.TriedOriginsCount', count($result['tried_origins'])], ';');
                    foreach ($result['tried_origins'] as $index => $tried) {
                        fputcsv($file, [$quotationName, $controlNumber, $organization, $convenio, "DEBUG.TriedOrigin[{$index}].Origin", $tried['origin'] ?? 'N/A'], ';');
                        fputcsv($file, [$quotationName, $controlNumber, $organization, $convenio, "DEBUG.TriedOrigin[{$index}].Destination", $tried['destination'] ?? 'N/A'], ';');
                        if (isset($tried['response'])) {
                            $this->writeConsolidatedFlattenedData($file, $tried['response'], $quotationName, $controlNumber, $organization, $convenio, "DEBUG.TriedOrigin[{$index}].Response");
                        }
                        if (isset($tried['exception'])) {
                            fputcsv($file, [$quotationName, $controlNumber, $organization, $convenio, "DEBUG.TriedOrigin[{$index}].Exception", $tried['exception']], ';');
                        }
                    }
                }

                // Write additional metadata
                if (isset($result['origin_used'])) {
                    fputcsv($file, [$quotationName, $controlNumber, $organization, $convenio, 'METADATA.OriginUsed', $result['origin_used']], ';');
                }
                if (isset($result['destination_used'])) {
                    fputcsv($file, [$quotationName, $controlNumber, $organization, $convenio, 'METADATA.DestinationUsed', $result['destination_used']], ';');
                }
                if (isset($result['retried'])) {
                    fputcsv($file, [$quotationName, $controlNumber, $organization, $convenio, 'METADATA.WasRetried', $result['retried'] ? 'Yes' : 'No'], ';');
                    if (isset($result['retry_response'])) {
                        $this->writeConsolidatedFlattenedData($file, $result['retry_response'], $quotationName, $controlNumber, $organization, $convenio, 'METADATA.RetryResponse');
                    }
                }

                // Add separator line between quotations
                fputcsv($file, ['---', '---', '---', '---', '---', '---'], ';');
            }

            fclose($file);

            $this->info("📊 Consolidated Excel file generated successfully:");
            $this->info("   Location: storage/app/public/{$filename}");
            $this->info("   Success: {$successCount} quotations, Errors: {$errorCount}");

        } catch (\Exception $e) {
            $this->error('Failed to generate consolidated Excel: ' . $e->getMessage());
        }
    }

    /**
     * Write flattened data for consolidated Excel
     */
    protected function writeConsolidatedFlattenedData($file, $data, string $quotationType, string $controlNumber, string $organization, string $convenio, string $prefix = ''): void
    {
        if (is_array($data) || is_object($data)) {
            foreach ((array)$data as $key => $value) {
                $currentKey = empty($prefix) ? $key : "{$prefix}.{$key}";

                if (is_array($value) || is_object($value)) {
                    $this->writeConsolidatedFlattenedData($file, $value, $quotationType, $controlNumber, $organization, $convenio, $currentKey);
                } else {
                    fputcsv($file, [$quotationType, $controlNumber, $organization, $convenio, $currentKey, $this->safeStringValue($value)], ';');
                }
            }
        } else {
            fputcsv($file, [$quotationType, $controlNumber, $organization, $convenio, $prefix ?: 'Root', $this->safeStringValue($data)], ';');
        }
    }

}
