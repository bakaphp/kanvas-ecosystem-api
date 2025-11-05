<?php

namespace App\Console\Commands\Connectors\DealerSocket;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Connectors\DealerSocket\CustomerClient;
use Kanvas\Regions\Models\Regions;

class CreateCustomerCommand extends Command
{
    protected $signature = 'dealersocket:create-customer 
                            {--type=Individual : Customer type (Individual|Company)}
                            {--interactive : Ask for input interactively}
                            {--company-id=1 : Company ID}
                            {--app-id=1 : App ID}
                            {--region-id=1 : Region ID}';

    protected $description = 'Create a new customer in DealerSocket';

    public function handle()
    {
        $company = CompaniesRepository::getById($this->option('company-id'));
        $app = Apps::getById($this->option('app-id'));
        $region = Regions::getById($this->option('region-id'));

        $this->info('🚀 Creating DealerSocket Customer...');
        $this->newLine();

        // Show environment info
        $environment = config('dealersocket.environment', 'production');
        
        if ($environment === 'testing') {
            $this->comment('📍 Using Testing Environment');
        } else {
            $this->comment('🚀 Using Production Environment');
        }
        $this->newLine();

        $customerType = $this->option('type');
        
        // Validate customer type
        if (!in_array($customerType, ['Individual', 'Company'])) {
            $this->error("❌ Invalid customer type: {$customerType}");
            $this->info('Valid types: Individual, Company');
            return Command::FAILURE;
        }

        $data = $this->option('interactive') 
            ? $this->getInteractiveInput($customerType) 
            : $this->getDefaultData($customerType);

        try {
            $client = new CustomerClient($company, $app, $region);
            $response = $client->createCustomer($data);

            // Parse XML response
            if ($response && isset($response->ProcessCustomerInformationDataArea)) {
                $customerInfo = $response->ProcessCustomerInformationDataArea
                    ->CustomerInformation
                    ->CustomerInformationDetail
                    ->CustomerParty ?? null;

                if ($customerInfo) {
                    $this->newLine();
                    $this->info('✅ Customer created successfully!');
                    $this->newLine();
                    
                    $tableData = [];
                    
                    // Entity ID
                    if ($customerType === 'Individual' && isset($customerInfo->SpecifiedPerson->ID)) {
                        $entityId = (string)$customerInfo->SpecifiedPerson->ID;
                        $tableData[] = ['Entity ID', $entityId];
                        
                        // Store for later use
                        cache()->put('last_created_customer_id', $entityId, now()->addHour());
                    } elseif ($customerType === 'Company' && isset($customerInfo->SpecifiedOrganization->ID)) {
                        $entityId = (string)$customerInfo->SpecifiedOrganization->ID;
                        $tableData[] = ['Entity ID', $entityId];
                        
                        cache()->put('last_created_customer_id', $entityId, now()->addHour());
                    }
                    
                    $tableData[] = ['Customer Type', $customerType];
                    
                    // Name
                    if ($customerType === 'Individual') {
                        $name = trim(
                            ($customerInfo->SpecifiedPerson->GivenName ?? '') . ' ' . 
                            ($customerInfo->SpecifiedPerson->FamilyName ?? '')
                        );
                        $tableData[] = ['Name', $name];
                    } else {
                        $companyName = (string)($customerInfo->SpecifiedOrganization->CompanyName ?? 'N/A');
                        $tableData[] = ['Company Name', $companyName];
                    }
                    
                    // Contact info
                    if (isset($customerInfo->SpecifiedPerson->TelephoneCommunication->CompleteNumber)) {
                        $tableData[] = ['Phone', (string)$customerInfo->SpecifiedPerson->TelephoneCommunication->CompleteNumber];
                    }
                    
                    if (isset($customerInfo->SpecifiedPerson->URICommunication->URIID)) {
                        $tableData[] = ['Email', (string)$customerInfo->SpecifiedPerson->URICommunication->URIID];
                    }
                    
                    $this->table(['Field', 'Value'], $tableData);
                    
                    // Show search tip
                    if (!empty($entityId)) {
                        $this->newLine();
                        $this->comment('💡 Tip: Use "php artisan dealersocket:search-customer ' . $entityId . '" to view this customer');
                    }
                    
                    return Command::SUCCESS;
                } else {
                    $this->error('❌ Customer created but response format unexpected');
                    $this->warn('Response: ' . $response->asXML());
                    return Command::FAILURE;
                }
            } else {
                $this->error('❌ Failed to create customer - Invalid response');
                if ($response) {
                    $this->warn('Response: ' . $response->asXML());
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

    private function getInteractiveInput(string $customerType): array
    {
        $this->info("📝 Enter {$customerType} customer information:");
        $this->newLine();

        $data = ['type' => $customerType];

        if ($customerType === 'Individual') {
            $this->comment('👤 Personal Information:');
            $data['firstName'] = $this->ask('First Name');
            $data['lastName'] = $this->ask('Last Name');
        } else {
            $this->comment('🏢 Company Information:');
            $data['companyName'] = $this->ask('Company Name');
            
            $this->newLine();
            $this->comment('👤 Primary Contact:');
            $data['contactFirstName'] = $this->ask('Contact First Name');
            $data['contactLastName'] = $this->ask('Contact Last Name');
        }

        $this->newLine();
        $this->comment('📞 Contact Information:');
        
        if ($this->confirm('Add phone number?', true)) {
            $data['phone'] = $this->ask('Phone Number');
        }

        if ($this->confirm('Add email?', true)) {
            $data['email'] = $this->ask('Email Address');
        }

        return $data;
    }

    private function getDefaultData(string $customerType): array
    {
        $timestamp = now()->timestamp;
        
        if ($customerType === 'Individual') {
            return [
                'type' => 'Individual',
                'firstName' => 'John',
                'lastName' => 'Doe',
                'phone' => '555-1234',
                'email' => "john.doe.{$timestamp}@example.com",
            ];
        } else {
            return [
                'type' => 'Company',
                'companyName' => 'Acme Corporation',
                'contactFirstName' => 'Jane',
                'contactLastName' => 'Smith',
                'phone' => '555-5678',
                'email' => "jane.smith.{$timestamp}@acme.com",
            ];
        }
    }
}