<?php

declare(strict_types=1);

namespace Kanvas\Console\Commands\Connectors\UniversalAssistance;

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
                          {--type=quote : Test type (quote, voucher, query)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send test requests to UniversalAssistance (quote, voucher, query)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting UniversalAssistance test...');

        try {
            $app = Apps::find(90);
            if (! $app) {
                $this->error('App with ID 90 not found');
                return 1;
            }

            // Create test order associated with app 90
            $order = \Kanvas\Souk\Orders\Models\Order::create([
                'apps_id' => $app->getId(),
                'users_id' => 1,
                'status' => 'pending',
                'total' => 10.00,
                'currency' => 'USD',
                'metadata' => [
                    'test_order' => true,
                    'universal_assistance_test' => now()->toISOString(),
                ]
            ]);

            $testType = $this->option('type');
            $this->info("Test type: {$testType}");

            $service = new UniversalAssistanceService($app, $order);

            switch ($testType) {
                case 'quote':
                    $result = $service->handleTravelQuote($this->getTestTravelData());
                    $this->info('Travel quote sent.');
                    $this->displayTravelData($this->getTestTravelData());
                    break;
                case 'voucher':
                    $person = new People(['firstname' => 'Juan', 'lastname' => 'Pérez', 'email' => 'juan.perez@test.com']);
                    $result = $service->handleVoucherCreation($this->getTestVoucherData(), $person);
                    $this->info('Voucher creation sent.');
                    $this->displayVoucherData($this->getTestVoucherData());
                    break;
                case 'query':
                    $result = $service->handleVoucherQuery($this->getTestQueryData());
                    $this->info('Voucher query sent.');
                    $this->displayQueryData($this->getTestQueryData());
                    break;
                default:
                    $this->error("Invalid test type: {$testType}");
                    $order->delete();
                    return 1;
            }

            $this->info('Test completed.');
            $this->displayResult($result);
            $order->delete();
        } catch (\Exception $e) {
            $this->error('Error in test: ' . $e->getMessage());
            $this->line('Stack trace:');
            $this->line($e->getTraceAsString());
            return 1;
        }

        return 0;
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
    private function displayResult(array $result): void
    {
        $this->line('📋 Response:');

        if (empty($result)) {
            $this->warn('Empty response');
            return;
        }

        // Display as table if associative array
        if (isset($result[0]) && is_array($result[0])) {
            // Is an array of arrays (multiple results)
            $headers = array_keys($result[0]);
            $rows = array_map(function ($item) {
                return array_map(fn ($value) => is_array($value) ? json_encode($value) : $value, $item);
            }, $result);
            $this->table($headers, $rows);
        } elseif (is_array($result) && ! isset($result[0])) {
            // Is an associative array (single result)
            $this->table(
                ['Field', 'Value'],
                array_map(
                    fn ($key, $value) => [$key, is_array($value) ? json_encode($value) : $value],
                    array_keys($result),
                    $result
                )
            );
        } else {
            // Other type of response
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        }
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
}
