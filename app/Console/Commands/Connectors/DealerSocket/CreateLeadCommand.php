<?php

namespace App\Console\Commands\Connectors\DealerSocket;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Connectors\DealerSocket\LeadClient;
use Kanvas\Regions\Models\Regions;


class CreateLeadCommand extends Command
{
    protected $signature = 'dealersocket:create-lead 
                            {--companyId=}
                            {--format=star : XML format (star|adf)}
                            {--interactive : Ask for input interactively
                            {--company-id=1 : Company ID}
                            {--app-id=1 : App ID}
                            {--region-id=1 : Region ID}';

    protected $description = 'Create a new lead in DealerSocket';

    public function handle()
    {
        $company = CompaniesRepository::getById($this->option('company-id'));
        $app = Apps::getById($this->option('app-id'));
        $region = Regions::getById($this->option('region-id'));

        $this->info('🚀 Creating DealerSocket Lead...');
        $this->newLine();

        // Show which URL will be used
        $format = $this->option('format');
        $environment = config('dealersocket.environment', 'production');
        $useOem = config('dealersocket.use_oem_testing_url', false);
        
        if ($environment === 'testing' || $useOem) {
            $this->comment("📍 Using OEM Testing URL ({$format} format)");
        } else {
            $this->comment("🚀 Using Production API URL");
        }
        $this->newLine();

        $data = $this->option('interactive') 
            ? $this->getInteractiveInput() 
            : $this->getDefaultData();

        try {

            $client = new LeadClient($company, $app, $region);

            // Usar los métodos que ya existen en LeadClient
            $response = $format === 'adf' 
                ? $client->createSalesLeadADF($data)
                : $client->createSalesLead($data);

            if ($response['success']) {
                $this->newLine();
                $this->info('✅ Lead created successfully!');
                $this->newLine();
                
                $this->table(
                    ['Field', 'Value'],
                    [
                        ['Lead ID', $response['leadId'] ?? 'N/A'],
                        ['Customer ID', $response['customerId'] ?? 'N/A'],
                        ['Dealer ID', $response['dealerId'] ?? 'N/A'],
                        ['Assigned To', $response['assignedName'] ?? 'N/A'],
                        ['Assigned ID', $response['assignedId'] ?? 'N/A'],
                        ['Existing Lead', ($response['existingLeadId'] ?? '0') !== '0' ? 'Yes' : 'No'],
                    ]
                );
                
                // Store lead ID for search command
                if (!empty($response['leadId'])) {
                    cache()->put('last_created_lead_id', $response['leadId'], now()->addHour());
                    
                    $this->newLine();
                    $this->comment('💡 Tip: Use "php artisan dealersocket:search-lead ' . $response['leadId'] . '" to view this lead');
                }
                
                return Command::SUCCESS;
            } else {
                $this->error('❌ Failed to create lead');
                $this->error('Error: ' . ($response['errorMessage'] ?? $response['error'] ?? 'Unknown error'));
                
                if (!empty($response['body'])) {
                    $this->newLine();
                    $this->warn('Response body:');
                    $this->line($response['body']);
                }
                
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error('❌ Exception: ' . $e->getMessage());
            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function getInteractiveInput(): array
    {
        $this->info('📝 Enter lead information:');
        $this->newLine();

        // Customer Info
        $this->comment('👤 Customer Information:');
        $data = [
            'firstName' => $this->ask('First Name'),
            'lastName' => $this->ask('Last Name'),
            'email' => $this->ask('Email'),
            'phone' => $this->ask('Phone'),
            'phoneType' => $this->choice('Phone Type', ['Day Phone', 'Evening Phone', 'Cell Phone'], 0),
            'phoneTime' => $this->choice('Best Time to Call', ['Day', 'Evening', 'Anytime'], 2),
        ];

        $this->newLine();
        
        // Address (optional)
        if ($this->confirm('Add address information?', false)) {
            $data['street'] = $this->ask('Street Address');
            $data['city'] = $this->ask('City');
            $data['state'] = $this->ask('State (2 letters)', 'FL');
            $data['zipCode'] = $this->ask('ZIP Code');
        }

        $this->newLine();

        // Vehicle Info
        $this->comment('🚗 Vehicle Information:');
        $data['interestedVehicle'] = [
            'year' => $this->ask('Year', date('Y')),
            'make' => $this->ask('Make', 'Toyota'),
            'model' => $this->ask('Model', 'Camry'),
            'status' => $this->choice('Vehicle Status', ['New', 'Used'], 0),
        ];

        if ($this->confirm('Add VIN?', false)) {
            $data['interestedVehicle']['vin'] = $this->ask('VIN');
        }

        if ($this->confirm('Add Stock Number?', false)) {
            $data['interestedVehicle']['stock'] = $this->ask('Stock Number');
        }

        $this->newLine();

        // Lead Info
        $data['leadInterestCode'] = $this->choice('Lead Interest', ['B' => 'Buy', 'L' => 'Lease', 'T' => 'Trade'], 'B');
        
        // Comments
        $data['customerComments'] = $this->confirm('Add customer comments?', false) 
            ? $this->ask('Customer Comments') 
            : '';
        
        $data['leadComments'] = $this->confirm('Add lead comments?', false) 
            ? $this->ask('Lead Comments') 
            : '';

        // Sales Person
        $data['salesPerson'] = $this->ask('Sales Person Name (optional)', '');

        // Add required fields with defaults
        $data = $this->addRequiredFields($data);

        return $data;
    }

    private function getDefaultData(): array
    {
        $data = [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john.doe@example.com',
            'phone' => '555-1234',
            'phoneType' => 'Cell Phone',
            'phoneTime' => 'Anytime',
            'interestedVehicle' => [
                'year' => date('Y'),
                'make' => 'Toyota',
                'model' => 'Camry',
                'status' => 'New',
            ],
            'leadInterestCode' => 'B',
            'customerComments' => 'Test lead from Laravel command',
            'leadComments' => 'Test lead',
            'salesPerson' => '',
        ];

        return $this->addRequiredFields($data);
    }

    /**
     * Add required fields for STAR format
     */
    private function addRequiredFields(array $data): array
    {
        // Generate unique IDs
        $timestamp = now()->timestamp;
        $random = substr(uniqid(), -6);
        
        // Required fields for STAR format
        $data['senderNameCode'] = $data['senderNameCode'] ?? config('dealersocket.vendor_name', 'VendorName');
        $data['serviceId'] = $data['serviceId'] ?? 'WEB_LEAD';
        $data['bodId'] = $data['bodId'] ?? 'lead_' . $timestamp . '_' . $random;
        $data['documentId'] = $data['documentId'] ?? 'DOC_' . $timestamp;
        
        // Customer type (relationship code)
        $data['customerType'] = $data['customerType'] ?? 'Prospect';
        
        // Contact method
        $data['contactMethod'] = $data['contactMethod'] ?? 'Phone';
        
        // Phone type mapping
        $data['phoneType'] = $this->mapPhoneType($data['phoneType'] ?? 'Cell Phone');
        
        // Lead source
        $data['leadSource'] = $data['leadSource'] ?? 'Website';
        
        // Address formatting (convert to nested array if needed)
        if (!empty($data['street']) && empty($data['address'])) {
            $data['address'] = [
                'street' => $data['street'] ?? '',
                'city' => $data['city'] ?? '',
                'state' => $data['state'] ?? '',
                'zipCode' => $data['zipCode'] ?? '',
            ];
        }
        
        // For ADF format
        $data['source'] = $data['source'] ?? $data['leadSource'];
        $data['namePart'] = $data['namePart'] ?? 'full';
        $data['providerName'] = $data['providerName'] ?? 'Website Lead Form';
        $data['service'] = $data['service'] ?? 'Web Lead';
        
        // Vehicle interest for ADF
        if (!empty($data['interestedVehicle'])) {
            $data['vehicle'] = $data['interestedVehicle'];
            $data['vehicle']['interest'] = $this->mapLeadInterestToADF($data['leadInterestCode'] ?? 'B');
        }
        
        // Ensure comments are never null
        $data['customerComments'] = $data['customerComments'] ?? '';
        $data['leadComments'] = $data['leadComments'] ?? '';
        $data['comments'] = $data['customerComments'] ?: $data['leadComments'];
        
        // Ensure sales person is not null
        $data['salesPerson'] = $data['salesPerson'] ?? '';
        
        return $data;
    }
    
    /**
     * Map phone type to STAR format channel code
     */
    private function mapPhoneType(string $type): string
    {
        return match($type) {
            'Day Phone' => 'Phone',
            'Evening Phone' => 'Phone',
            'Cell Phone' => 'Mobile',
            'Home Phone' => 'Phone',
            default => 'Phone',
        };
    }

    /**
     * Map STAR lead interest code to ADF interest
     */
    private function mapLeadInterestToADF(string $code): string
    {
        return match($code) {
            'B' => 'buy',
            'L' => 'lease',
            'T' => 'trade-in',
            default => 'buy',
        };
    }
}