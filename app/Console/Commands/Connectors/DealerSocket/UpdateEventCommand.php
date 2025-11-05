<?php

namespace App\Console\Commands\Connectors\DealerSocket;

use Exception;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Connectors\DealerSocket\LeadClient;
use Kanvas\Regions\Models\Regions;

class UpdateEventCommand extends Command
{
    protected $signature = 'dealersocket:update-event
                            {--type=sales : Event type (sales|service)}
                            {--event-id= : DealerSocket Event ID}
                            {--entity-id= : DealerSocket Customer/Entity ID}
                            {--activity-id= : Activity ID (required for service events)}
                            {--interactive : Use interactive mode}';

    protected $description = 'Update an existing DealerSocket event (lead or service appointment)';

    public function handle()
    {
        $company = CompaniesRepository::getById($this->option('company-id'));
        $app = Apps::getById($this->option('app-id'));
        $region = Regions::getById($this->option('region-id'));

        $this->info('🔄 Updating DealerSocket Event...');
        $this->newLine();

        // Show environment info
        $environment = config('dealersocket.environment', 'production');

        if ($environment === 'testing') {
            $this->comment('📍 Using Testing Environment');
        } else {
            $this->comment('🚀 Using Production Environment');
        }
        $this->newLine();

        try {
            $client = new LeadClient($company, $app, $region);

            $type = $this->option('type');

            if ($type === 'sales') {
                return $this->updateSalesEvent($client);
            } elseif ($type === 'service') {
                return $this->updateServiceEvent($client);
            } else {
                $this->error("❌ Invalid event type: {$type}");
                $this->info('Valid types: sales, service');

                return Command::FAILURE;
            }
        } catch (Exception $e) {
            $this->error('❌ Exception: ' . $e->getMessage());
            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    private function updateSalesEvent(LeadClient $client)
    {
        $this->info('📝 Updating Sales Event (Lead)');
        $this->newLine();

        // Get Event ID
        $eventId = $this->option('event-id');
        if (! $eventId && $this->option('interactive')) {
            $eventId = $this->ask('Enter Event/Lead ID');
        }

        if (! $eventId) {
            $this->error('❌ Event ID is required. Use --event-id=XXX or --interactive');

            return Command::FAILURE;
        }

        // Get Entity ID
        $entityId = $this->option('entity-id');
        if (! $entityId && $this->option('interactive')) {
            $entityId = $this->ask('Enter Entity/Customer ID');
        }

        if (! $entityId) {
            $this->error('❌ Entity ID is required. Use --entity-id=XXX or --interactive');

            return Command::FAILURE;
        }

        // Build update data
        $data = $this->option('interactive')
            ? $this->getSalesEventDataInteractive()
            : $this->getSalesEventDataExample();

        // Show summary before sending
        $this->newLine();
        $this->comment('📋 Update Summary:');
        $this->table(
            ['Field', 'Value'],
            [
                ['Event ID', $eventId],
                ['Entity ID', $entityId],
                ['Status', $data['leadStatus'] ?? 'No change'],
                ['Priority', $this->getPriorityLabel($data['priorityRanking'] ?? null)],
                ['Sales Person', $data['salesPersonName'] ?? 'No change'],
                ['Purchase Type', $this->getLeadInterestLabel($data['leadInterestCode'] ?? null)],
            ]
        );
        $this->newLine();

        if ($this->option('interactive')) {
            if (! $this->confirm('Proceed with update?', true)) {
                $this->info('Update cancelled.');

                return Command::SUCCESS;
            }
            $this->newLine();
        }

        // Execute update
        $this->info('⏳ Sending update to DealerSocket...');

        $response = $client->updateSalesEvent(
            eventId: (int)$eventId,
            entityId: (int)$entityId,
            data: $data
        );

        // Display results
        if ($response['success']) {
            $this->newLine();
            $this->info('✅ Event updated successfully!');
            $this->newLine();

            $this->table(
                ['Field', 'Value'],
                [
                    ['Event ID', $eventId],
                    ['Entity ID', $entityId],
                    ['Status', 'Updated'],
                ]
            );

            // Cache for search command
            cache()->put('last_updated_event_id', $eventId, now()->addHour());
            cache()->put('last_updated_entity_id', $entityId, now()->addHour());

            $this->newLine();
            $this->comment("💡 Tip: Use \"php artisan dealersocket:search-lead --entity-id={$entityId}\" to verify the changes");

            return Command::SUCCESS;
        } else {
            $this->error('❌ Failed to update event');
            $this->error('Error: ' . ($response['errorMessage'] ?? $response['error'] ?? 'Unknown error'));

            if (! empty($response['errorCode'])) {
                $this->error('Error Code: ' . $response['errorCode']);
            }

            if (! empty($response['stackTrace'])) {
                $this->newLine();
                $this->warn('Stack Trace:');
                $this->line($response['stackTrace']);
            }

            if (! empty($response['rawXml']) && $this->option('verbose')) {
                $this->newLine();
                $this->warn('Response XML:');
                $this->line($response['rawXml']);
            }

            $this->newLine();
            $this->displayErrorHelp();

            return Command::FAILURE;
        }
    }

    private function updateServiceEvent(LeadClient $client)
    {
        $this->info('🔧 Updating Service Event (Appointment)');
        $this->newLine();

        // Get Event ID
        $eventId = $this->option('event-id');
        if (! $eventId && $this->option('interactive')) {
            $eventId = $this->ask('Enter Event ID');
        }

        if (! $eventId) {
            $this->error('❌ Event ID is required. Use --event-id=XXX or --interactive');

            return Command::FAILURE;
        }

        // Get Activity ID
        $activityId = $this->option('activity-id');
        if (! $activityId && $this->option('interactive')) {
            $activityId = $this->ask('Enter Activity ID (from appointment)');
        }

        if (! $activityId) {
            $this->error('❌ Activity ID is required for service events. Use --activity-id=XXX or --interactive');

            return Command::FAILURE;
        }

        // Get Entity ID
        $entityId = $this->option('entity-id');
        if (! $entityId && $this->option('interactive')) {
            $entityId = $this->ask('Enter Entity/Customer ID');
        }

        if (! $entityId) {
            $this->error('❌ Entity ID is required. Use --entity-id=XXX or --interactive');

            return Command::FAILURE;
        }

        // Build update data
        $data = $this->option('interactive')
            ? $this->getServiceEventDataInteractive()
            : $this->getServiceEventDataExample();

        // Show summary
        $this->newLine();
        $this->comment('📋 Update Summary:');
        $this->table(
            ['Field', 'Value'],
            [
                ['Event ID', $eventId],
                ['Activity ID', $activityId],
                ['Entity ID', $entityId],
                ['Status', $data['appointmentStatus'] ?? 'No change'],
                ['Appointment Date', $data['appointmentDateTime'] ?? 'No change'],
            ]
        );
        $this->newLine();

        if ($this->option('interactive')) {
            if (! $this->confirm('Proceed with update?', true)) {
                $this->info('Update cancelled.');

                return Command::SUCCESS;
            }
            $this->newLine();
        }

        // Execute update
        $this->info('⏳ Sending update to DealerSocket...');

        $response = $client->updateServiceEvent(
            eventId: (int)$eventId,
            activityId: (int)$activityId,
            entityId: (int)$entityId,
            data: $data
        );

        // Display results
        if ($response['success']) {
            $this->newLine();
            $this->info('✅ Service appointment updated successfully!');
            $this->newLine();

            $this->table(
                ['Field', 'Value'],
                [
                    ['Event ID', $eventId],
                    ['Activity ID', $activityId],
                    ['Entity ID', $entityId],
                    ['Status', 'Updated'],
                ]
            );

            // Cache for search command
            cache()->put('last_updated_service_event_id', $eventId, now()->addHour());
            cache()->put('last_updated_entity_id', $entityId, now()->addHour());

            $this->newLine();
            $this->comment("💡 Tip: Use \"php artisan dealersocket:search-lead --entity-id={$entityId}\" to verify the changes");

            return Command::SUCCESS;
        } else {
            $this->error('❌ Failed to update service appointment');
            $this->error('Error: ' . ($response['errorMessage'] ?? $response['error'] ?? 'Unknown error'));

            if (! empty($response['errorCode'])) {
                $this->error('Error Code: ' . $response['errorCode']);
            }

            if (! empty($response['body']) && $this->option('verbose')) {
                $this->newLine();
                $this->warn('Response body:');
                $this->line($response['body']);
            }

            $this->newLine();
            $this->displayErrorHelp();

            return Command::FAILURE;
        }
    }

    private function getSalesEventDataInteractive(): array
    {
        $this->comment('📝 Enter update information (leave empty to skip field)');
        $this->newLine();

        $data = [];

        // Lead Status
        $this->comment('📊 Lead Status:');
        $statusOptions = [
            'Skip (no change)',
            'Contacted',
            'Unqualified',
            'Demo',
            'Write-Up',
            'Pending F&I',
            'Lost',
            'Store Visit',
        ];

        $statusChoice = $this->choice('Lead Status', $statusOptions, 0);
        if ($statusChoice !== 'Skip (no change)') {
            $data['leadStatus'] = $statusChoice;
        }
        $this->newLine();

        // Priority/Ranking
        $this->comment('🎯 Priority:');
        $priorityChoice = $this->choice(
            'Priority Ranking',
            ['Skip', '1 - Hot', '2 - Medium', '3 - Cold'],
            0
        );

        if ($priorityChoice !== 'Skip') {
            $data['priorityRanking'] = (int)substr($priorityChoice, 0, 1);
        }
        $this->newLine();

        // Lead Interest Code
        $this->comment('💰 Purchase Type:');
        $interestChoice = $this->choice(
            'Purchase Type',
            ['Skip', 'B - Buy', 'L - Lease'],
            0
        );

        if ($interestChoice !== 'Skip') {
            $data['leadInterestCode'] = substr($interestChoice, 0, 1);
        }
        $this->newLine();

        // Sale Class Code
        $this->comment('🚗 Vehicle Type:');
        $saleClassChoice = $this->choice(
            'Vehicle Type',
            ['Skip', 'New', 'Used', 'Demo', 'Other'],
            0
        );

        if ($saleClassChoice !== 'Skip') {
            $data['saleClassCode'] = $saleClassChoice;
        }
        $this->newLine();

        // Lead Comments
        $this->comment('💬 Comments:');
        $comments = $this->ask('Lead Comments/Description (optional)');
        if ($comments) {
            $data['leadComments'] = $comments;
        }
        $this->newLine();

        // Sales Person
        $this->comment('👤 Assignment:');
        $salesPerson = $this->ask('Sales Person Username (optional)');
        if ($salesPerson) {
            $data['salesPersonName'] = $salesPerson;
        }

        // BDC Assigned User
        $bdcUser = $this->ask('BDC Assigned Username (optional)');
        if ($bdcUser) {
            $data['bdcAssignedUser'] = $bdcUser;
        }
        $this->newLine();

        // Vehicle of Interest
        $this->comment('🚙 Vehicle of Interest:');
        if ($this->confirm('Update vehicle of interest?', false)) {
            $data['interestedVehicle'] = [
                'year' => $this->ask('Vehicle Year', date('Y')),
                'make' => $this->ask('Vehicle Make', 'Toyota'),
                'model' => $this->ask('Vehicle Model', 'Camry'),
                'stockNumber' => $this->ask('Stock Number (optional)', ''),
                'vin' => $this->ask('VIN (optional)', ''),
            ];
            $this->newLine();
        }

        // Trade-in
        $this->comment('🔄 Trade-in Vehicles:');
        if ($this->confirm('Add/Update trade-in vehicle?', false)) {
            $data['tradeInVehicles'] = [];

            $maxTradeIns = 3;
            $currentCount = 0;

            do {
                $this->info('Trade-in #' . ($currentCount + 1) . ':');

                $tradeIn = [
                    'year' => $this->ask('Trade-in Year', date('Y') - 5),
                    'make' => $this->ask('Trade-in Make', 'Honda'),
                    'model' => $this->ask('Trade-in Model', 'Civic'),
                    'vin' => $this->ask('Trade-in VIN (optional)', ''),
                    'mileage' => $this->ask('Mileage (optional)', ''),
                    'balanceAmount' => $this->ask('Loan Balance (optional)', ''),
                ];

                $data['tradeInVehicles'][] = $tradeIn;
                $currentCount++;
            } while (
                $currentCount < $maxTradeIns &&
                $this->confirm('Add another trade-in? (max 3)', false)
            );

            $this->newLine();
        }

        return $data;
    }

    private function getSalesEventDataExample(): array
    {
        return [
            'leadStatus' => 'Contacted',
            'priorityRanking' => 1,
            'leadInterestCode' => 'B',
            'saleClassCode' => 'New',
            'leadComments' => 'Updated via API - Customer very interested in ' . date('Y') . ' models',
            'salesPersonName' => 'dthompson',
            'interestedVehicle' => [
                'year' => date('Y'),
                'make' => 'Honda',
                'model' => 'Accord',
                'stockNumber' => 'H' . date('Y') . '-001',
            ],
        ];
    }

    private function getServiceEventDataInteractive(): array
    {
        $this->comment('📝 Enter service appointment update information');
        $this->newLine();

        $data = [];

        // Appointment Status
        $this->comment('📊 Appointment Status:');
        $statusOptions = [
            'Skip (no change)',
            'Open',
            'Confirmed',
            'Completed',
            'No Show',
            'Canceled',
            'Unqualified',
        ];

        $statusChoice = $this->choice('Appointment Status', $statusOptions, 0);
        if ($statusChoice !== 'Skip (no change)') {
            $data['appointmentStatus'] = $statusChoice;
        }
        $this->newLine();

        // Appointment Date/Time
        $this->comment('📅 Appointment Date:');
        $appointmentDate = $this->ask('Appointment Date/Time (Y-m-d H:i:s or leave empty to skip)', '');
        if ($appointmentDate) {
            try {
                $data['appointmentDateTime'] = date('Y-m-d\TH:i:s', strtotime($appointmentDate));
            } catch (Exception $e) {
                $this->warn('Invalid date format, skipping...');
            }
        }
        $this->newLine();

        // Appointment Notes
        $this->comment('💬 Notes:');
        $notes = $this->ask('Appointment Notes (optional)', '');
        if ($notes) {
            $data['appointmentNotes'] = $notes;
        }
        $this->newLine();

        // Service Advisor
        $this->comment('👤 Service Advisor:');
        $advisor = $this->ask('Service Advisor Username (optional)', '');
        if ($advisor) {
            $data['serviceAdvisor'] = $advisor;
        }
        $this->newLine();

        // Transportation
        $this->comment('🚗 Transportation:');
        $transportOptions = [
            'Skip',
            'None',
            'Wait',
            'Shuttle (2-way)',
            'Shuttle (Drop off)',
            'Shuttle (Pick up)',
            'Rental',
            'Loaner',
            'Valet Pick-up',
        ];

        $transportChoice = $this->choice('Alternate Transportation', $transportOptions, 0);
        if ($transportChoice !== 'Skip') {
            $data['alternateTransportation'] = $transportChoice;
        }
        $this->newLine();

        // Vehicle mileage
        $this->comment('🔧 Vehicle Info:');
        $mileage = $this->ask('Vehicle Mileage (optional)', '');
        if ($mileage) {
            $data['vehicle'] = ['mileage' => $mileage];
        }
        $this->newLine();

        return $data;
    }

    private function getServiceEventDataExample(): array
    {
        return [
            'appointmentStatus' => 'Confirmed',
            'appointmentDateTime' => now()->addDays(2)->format('Y-m-d\TH:i:s'),
            'appointmentNotes' => 'Customer confirmed appointment - Updated via API',
            'serviceAdvisor' => 'kgakramer',
            'alternateTransportation' => 'Loaner',
            'vehicle' => [
                'mileage' => 52000,
            ],
            'appointmentMethod' => 'Web',
        ];
    }

    private function getPriorityLabel(?int $priority): string
    {
        if ($priority === null) {
            return 'No change';
        }

        return match ($priority) {
            1 => '1 - Hot',
            2 => '2 - Medium',
            3 => '3 - Cold',
            default => 'No change',
        };
    }

    private function getLeadInterestLabel(?string $code): string
    {
        if ($code === null) {
            return 'No change';
        }

        return match ($code) {
            'B' => 'Buy',
            'L' => 'Lease',
            'T' => 'Trade',
            default => 'No change',
        };
    }

    private function displayErrorHelp(): void
    {
        $this->info('💡 Common Error Codes:');
        $this->table(
            ['Error Code', 'Description', 'Solution'],
            [
                ['EVENTID_INVALID', 'Event does not exist', 'Verify Event ID is correct'],
                ['NO_DATA', 'Invalid or missing data', 'Check required fields'],
                ['INTERNAL_ERROR', 'DealerSocket error', 'Retry in 5 minutes'],
                ['Event is Sold', 'Cannot update sold events', 'Event status is already "Sold"'],
                ['ACCOUNT_DEACTIVATED', 'Account not active', 'Contact DealerSocket support'],
            ]
        );
    }
}
