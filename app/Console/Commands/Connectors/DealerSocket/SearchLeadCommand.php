<?php

namespace App\Console\Commands\Connectors\DealerSocket;

use Illuminate\Console\Command;
use Kanvas\Connectors\DealerSocket\LeadClient;
use Kanvas\Connectors\DealerSocket\CustomerClient;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Regions\Models\Regions;

class SearchLeadCommand extends Command
{
    protected $signature = 'dealersocket:search-lead 
                            {--entity-id= : DealerSocket Entity/Customer ID}
                            {--email= : Customer email (will find entity-id first)}
                            {--phone= : Customer phone (will find entity-id first)}
                            {--event-id= : Specific Event/Lead ID (requires entity-id)}
                            {--category=Sales : Event category (Sales or Service)}
                            {--interactive : Interactive mode - ask for search criteria}
                            {--company-id=1 : Company ID}
                            {--app-id=1 : App ID}
                            {--region-id=1 : Region ID}';

    protected $description = 'Search for leads/events in DealerSocket';

    public function handle()
    {
        $this->info('🔍 Searching DealerSocket Leads...');
        $this->newLine();

        try {
            $company = CompaniesRepository::getById($this->option('company-id'));
            $app = Apps::getById($this->option('app-id'));
            $region = Regions::getById($this->option('region-id'));

            $leadClient = new LeadClient($company, $app, $region);

            // Get search parameters (interactive or from options)
            if ($this->option('interactive')) {
                $params = $this->getInteractiveParams();
                $entityId = $params['entityId'];
                $email = $params['email'];
                $phone = $params['phone'];
                $eventId = $params['eventId'];
                $category = $params['category'];
            } else {
                $entityId = $this->option('entity-id');
                $email = $this->option('email');
                $phone = $this->option('phone');
                $eventId = $this->option('event-id');
                $category = $this->option('category');
            }

            // If email or phone provided, find entity-id first
            if ($email || $phone) {
                $entityId = $this->findEntityId($company, $app, $region, $email, $phone);
                
                if (!$entityId) {
                    $this->error('❌ Customer not found with provided email/phone');
                    return 1;
                }
                
                $this->info("✓ Found customer with Entity ID: {$entityId}");
                $this->newLine();
            }

            // Validate we have entity-id
            if (!$entityId) {
                $this->error('❌ Please provide --entity-id, --email, or --phone');
                return 1;
            }

            // Search for leads
            if ($eventId) {
                // Search for specific lead
                $this->info("Searching for Lead ID: {$eventId}");
                $result = $leadClient->searchByLeadId((int) $eventId, (int) $entityId);
                
                if (empty($result)) {
                    $this->warn('Lead not found.');
                    return 1;
                }
                
                $this->displaySingleLead($result);
                
            } else {
                // Search all leads for customer
                $this->info("Searching {$category} leads for Entity ID: {$entityId}");
                $result = $leadClient->searchLeadsByEntityId((int) $entityId, $category);
                $this->displayLeadResults($result);
            }

            $this->newLine();
            $this->info('✅ Search completed successfully!');
            return 0;

        } catch (\Exception $e) {
            $this->newLine();
            $this->error('❌ Exception: ' . $e->getMessage());
            
            if ($this->output->isVerbose()) {
                $this->error($e->getTraceAsString());
            }
            
            return 1;
        }
    }

    /**
     * Find entity ID by email or phone
     */
    private function findEntityId(Companies $company, Apps $app, Regions $region, ?string $email, ?string $phone): ?int
    {
        $this->info('🔎 Finding customer first...');
        
        try {
            $customerClient = new CustomerClient($company, $app, $region);
            
            $criteria = [];
            if ($email) {
                $criteria['email'] = $email;
                $this->line("  Searching by email: {$email}");
            } elseif ($phone) {
                $criteria['phone'] = $phone;
                $this->line("  Searching by phone: {$phone}");
            }
            
            $customer = $customerClient->searchCustomer($criteria);
            
            if (empty($customer['entityId'])) {
                return null;
            }
            
            // Display found customer info
            if (!empty($customer['firstName']) || !empty($customer['lastName'])) {
                $name = trim(($customer['firstName'] ?? '') . ' ' . ($customer['lastName'] ?? ''));
                $this->line("  Found: <comment>{$name}</comment>");
            }
            
            return (int) $customer['entityId'];
            
        } catch (\Exception $e) {
            $this->warn("  Error searching customer: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Display full lead search results
     */
    private function displayLeadResults(array $result): void
    {
        if (empty($result)) {
            $this->warn('No results found.');
            return;
        }

        // Display customer info
        if (!empty($result['customer'])) {
            $this->displayCustomerInfo($result['customer']);
        }

        // Display leads/events
        if (empty($result['events'])) {
            $this->warn('📭 No active leads found for this customer.');
            return;
        }

        $this->newLine();
        $this->info('📋 Active Leads/Events:');
        $this->info(str_repeat('─', 80));

        foreach ($result['events'] as $index => $event) {
            $this->displayEvent($event, $index + 1);
        }

        $this->newLine();
        $this->info("Total Leads Found: " . count($result['events']));
    }

    /**
     * Display single lead result
     */
    private function displaySingleLead(array $event): void
    {
        if (empty($event)) {
            $this->warn('Lead not found.');
            return;
        }

        $this->info('📋 Lead Details:');
        $this->info(str_repeat('─', 80));
        $this->displayEvent($event, 1);
    }

    /**
     * Display customer information
     */
    private function displayCustomerInfo(array $customer): void
    {
        $this->newLine();
        $this->info('👤 Customer Information:');
        $this->info(str_repeat('─', 80));
        
        $name = trim(implode(' ', array_filter([
            $customer['firstName'] ?? '',
            $customer['middleName'] ?? '',
            $customer['lastName'] ?? '',
            $customer['suffix'] ?? ''
        ])));
        
        if ($name) {
            $this->line("  Name: <comment>{$name}</comment>");
        }
        
        if (!empty($customer['companyName'])) {
            $this->line("  Company: <comment>{$customer['companyName']}</comment>");
        }
    }

    /**
     * Display single event/lead
     */
    private function displayEvent(array $event, int $number): void
    {
        $this->newLine();
        $this->line("  <fg=cyan>Lead #{$number}</>");
        $this->line("  ├─ Event ID: <comment>{$event['eventId']}</comment>");
        
        $category = $event['eventCategory'] === 1 ? 'Sales' : 
                   ($event['eventCategory'] === 2 ? 'Service' : 'Unknown');
        $this->line("  ├─ Category: <comment>{$category}</comment>");
        
        if (!empty($event['statusName'])) {
            $statusColor = $this->getStatusColor($event['status']);
            $this->line("  ├─ Status: <fg={$statusColor}>{$event['statusName']}</>");
        }
        
        if (!empty($event['personAssigned'])) {
            $this->line("  ├─ Assigned To: <comment>{$event['personAssigned']}</comment>");
        }
        
        if (!empty($event['insertDate'])) {
            $this->line("  ├─ Created: <comment>{$event['insertDate']}</comment>");
        }
        
        if (!empty($event['updateDate'])) {
            $this->line("  ├─ Updated: <comment>{$event['updateDate']}</comment>");
        }
        
        // Vehicle information
        $vehicle = $event['vehicle'] ?? [];
        if (!empty(array_filter($vehicle))) {
            $this->line("  └─ Vehicle:");
            
            $vehicleDesc = trim(implode(' ', array_filter([
                $vehicle['year'] ?? '',
                $vehicle['make'] ?? '',
                $vehicle['model'] ?? ''
            ])));
            
            if ($vehicleDesc) {
                $this->line("     ├─ Description: <comment>{$vehicleDesc}</comment>");
            }
            
            if (!empty($vehicle['vin'])) {
                $this->line("     ├─ VIN: <comment>{$vehicle['vin']}</comment>");
            }
            
            if (!empty($vehicle['stockNumber'])) {
                $this->line("     ├─ Stock: <comment>{$vehicle['stockNumber']}</comment>");
            }
            
            if (!empty($vehicle['currentMileage'])) {
                $this->line("     └─ Mileage: <comment>{$vehicle['currentMileage']}</comment>");
            }
        }
    }

    /**
     * Get color for status based on status code
     */
    private function getStatusColor(?int $status): string
    {
        if ($status === null) {
            return 'default';
        }

        // Sold, Completed
        if (in_array($status, [225, 100169])) {
            return 'green';
        }
        
        // Lost
        if (in_array($status, [226, 100170])) {
            return 'red';
        }
        
        // Unqualified
        if (in_array($status, [220, 100165])) {
            return 'gray';
        }
        
        // Active statuses
        return 'yellow';
    }

    /**
     * Get search parameters interactively
     */
    private function getInteractiveParams(): array
    {
        $this->info('📝 Interactive Search Mode');
        $this->newLine();

        $searchMethod = $this->choice(
            'How would you like to search?',
            [
                '1' => 'By Entity/Customer ID',
                '2' => 'By Email',
                '3' => 'By Phone',
            ],
            '1'
        );

        $params = [
            'entityId' => null,
            'email' => null,
            'phone' => null,
            'eventId' => null,
            'category' => 'Sales',
        ];

        switch ($searchMethod) {
            case '1':
                $params['entityId'] = $this->ask('Enter Entity/Customer ID');
                break;
            case '2':
                $params['email'] = $this->ask('Enter customer email');
                break;
            case '3':
                $params['phone'] = $this->ask('Enter customer phone');
                break;
        }

        // Ask for category
        $category = $this->choice(
            'What type of leads/events?',
            ['Sales', 'Service'],
            'Sales'
        );
        $params['category'] = $category;

        // Ask if searching for specific event ID
        if ($params['entityId'] && $this->confirm('Do you want to search for a specific Lead/Event ID?', false)) {
            $params['eventId'] = $this->ask('Enter Lead/Event ID');
        }

        $this->newLine();
        
        return $params;
    }
}