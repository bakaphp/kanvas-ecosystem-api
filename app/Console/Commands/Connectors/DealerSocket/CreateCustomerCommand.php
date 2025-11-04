<?php

namespace App\Console\Commands\Connectors\DealerSocket;

class CreateCustomer extends BaseDealerSocketCommand
{
    protected $signature = 'dealersocket:create-customer
                            {--type=Individual : Tipo de cliente (Individual o Company)}
                            {--first-name= : Nombre}
                            {--last-name= : Apellido}
                            {--company-name= : Nombre de empresa}
                            {--email= : Email}
                            {--phone= : Teléfono}
                            {--interactive : Modo interactivo}
                            {--force : No pedir confirmación}
                            {--show-config : Mostrar configuración}';

    protected $description = 'Crea un nuevo cliente en DealerSocket';

    public function handle()
    {
        $this->info("🚀 DealerSocket - Crear Cliente\n");
        
        if ($this->option('show-config')) {
            $this->displayConfig();
        }

        $data = $this->option('interactive') 
            ? $this->collectDataInteractive() 
            : $this->collectDataFromOptions();

        if (!$this->validateData($data)) {
            return 1;
        }

        $this->displaySummary($data);

        if (!$this->confirmAction('¿Deseas crear este cliente?')) {
            $this->warn('❌ Operación cancelada');
            return 0;
        }

        try {
            $this->info("\n⏳ Creando cliente...");
            $createResponse = $this->customerClient->createCustomer($data);
            $this->displayXmlResponse($createResponse, 'Resultado');

            $entityId = null;
            if (is_object($createResponse)) {
                $entityId = isset($createResponse->EntityId) ? (int)$createResponse->EntityId : null;
            } elseif (is_array($createResponse)) {
                $entityId = $createResponse['entityId'] ?? $createResponse['EntityId'] ?? null;
            }

            if (!$entityId) {
                $this->warn('⚠️  Cliente creado pero no se pudo obtener el Entity ID');
                return 0;
            }

            $this->info("✅ Cliente creado exitosamente!");
            $this->line("🆔 Entity ID: {$entityId}");

            // Buscar el cliente recién creado
            $this->line('');
            $this->info('Buscando información completa del cliente...');
            
            sleep(2);
            
            $customerData = $this->customerClient->searchCustomer(['entityId' => $entityId]);

            // Mostrar información del cliente buscado
            $this->displayCustomerSearchResponse($customerData);

            return 0;
        } catch (\Exception $e) {
            $this->displayError($e);
            return 1;
        }
    }
    
    protected function collectDataInteractive(): array
    {
        $type = $this->choice(
            '¿Qué tipo de cliente?',
            ['Individual', 'Company'],
            0
        );
        
        $data = ['type' => $type];
        
        if ($type === 'Individual') {
            $data['firstName'] = $this->ask('Nombre');
            $data['lastName'] = $this->ask('Apellido');
        } else {
            $data['companyName'] = $this->ask('Nombre de la empresa');
        }
        
        $data['email'] = $this->ask('Email (opcional)');
        $data['phone'] = $this->ask('Teléfono (opcional)');
        
        return array_filter($data);
    }
    
    protected function collectDataFromOptions(): array
    {
        return array_filter([
            'type' => $this->option('type'),
            'firstName' => $this->option('first-name'),
            'lastName' => $this->option('last-name'),
            'companyName' => $this->option('company-name'),
            'email' => $this->option('email'),
            'phone' => $this->option('phone'),
        ]);
    }
    
    protected function validateData(array $data): bool
    {
        if ($data['type'] === 'Individual') {
            if (empty($data['firstName']) || empty($data['lastName'])) {
                $this->error('❌ Para tipo Individual se requiere nombre y apellido');
                return false;
            }
        } else {
            if (empty($data['companyName'])) {
                $this->error('❌ Para tipo Company se requiere nombre de empresa');
                return false;
            }
        }
        
        return true;
    }
    
    protected function displaySummary(array $data)
    {
        $this->info("\n📋 Resumen del cliente:");
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        
        foreach ($data as $key => $value) {
            $this->line(ucfirst($key) . ": " . $value);
        }
        
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }

    /**
     * Mostrar respuesta de búsqueda de cliente (ShowCustomerInformation)
     */
    protected function displayCustomerSearchResponse($response)
    {
        if (!is_object($response)) {
            $this->warn('⚠️  Respuesta no válida');
            return;
        }

        $this->line('');
        $this->info(str_repeat('═', 60));
        $this->info('📊 INFORMACIÓN COMPLETA DEL CLIENTE');
        $this->info(str_repeat('═', 60));
        $this->line('');

        // Verificar éxito
        $responseExpression = (string)($response->ShowCustomerInformationDataArea->Show->ResponseCriteria->ResponseExpression ?? '');
        
        if (strtolower($responseExpression) !== 'success') {
            $this->error("❌ Búsqueda fallida: {$responseExpression}");
            return;
        }

        $this->info('✅ Búsqueda exitosa');
        $this->line('');

        // Extraer datos
        $customerInfo = $response->ShowCustomerInformationDataArea->CustomerInformation ?? null;
        
        if (!$customerInfo) {
            $this->warn('⚠️  No se encontró información del cliente');
            return;
        }

        $detail = $customerInfo->CustomerInformationDetail ?? null;
        $party = $detail->CustomerParty ?? null;

        if (!$party) {
            $this->warn('⚠️  Datos del cliente incompletos');
            return;
        }

        $entityId = (string)($party->PartyID ?? 'N/A');
        $dmsId = (string)($party->DealerManagementSystemID ?? 'N/A');
        
        // Datos básicos
        $tableData = [
            ['Entity ID', $entityId],
            ['DMS Customer ID', $dmsId],
        ];

        // Si es empresa
        if (isset($party->SpecifiedOrganization)) {
            $org = $party->SpecifiedOrganization;
            $tableData[] = ['Tipo', 'Empresa'];
            $tableData[] = ['Nombre Empresa', (string)($org->CompanyName ?? 'N/A')];
            
            // Contacto principal
            if (isset($org->PrimaryContact->SpecifiedPerson)) {
                $person = $org->PrimaryContact->SpecifiedPerson;
                $fullName = trim((string)($person->GivenName ?? '') . ' ' . (string)($person->FamilyName ?? ''));
                if ($fullName) {
                    $tableData[] = ['Contacto', $fullName];
                }
                $tableData[] = ['Género', (string)($person->GenderCode ?? 'N/A')];
            }
            
            // Dirección
            if (isset($org->PostalAddress)) {
                $address = $org->PostalAddress;
                $tableData[] = ['Dirección', (string)($address->LineOne ?? 'N/A')];
                $tableData[] = ['Ciudad', (string)($address->CityName ?? 'N/A')];
                $tableData[] = ['Estado', (string)($address->StateOrProvinceCountrySubDivisionID ?? 'N/A')];
                $tableData[] = ['Código Postal', (string)($address->Postcode ?? 'N/A')];
            }
            
            // Teléfonos
            if (isset($org->PrimaryContact->TelephoneCommunication)) {
                foreach ($org->PrimaryContact->TelephoneCommunication as $phone) {
                    $tableData[] = [
                        'Teléfono (' . ($phone->UseCode ?? 'N/A') . ')',
                        (string)($phone->CompleteNumber ?? 'N/A')
                    ];
                }
            }
            
            // Email
            if (isset($org->PrimaryContact->SpecifiedPerson->URICommunication)) {
                $tableData[] = ['Email', (string)($org->PrimaryContact->SpecifiedPerson->URICommunication->URIID ?? 'N/A')];
            }
        }
        
        // Si es persona individual
        if (isset($party->SpecifiedPerson)) {
            $person = $party->SpecifiedPerson;
            $tableData[] = ['Tipo', 'Individual'];
            $tableData[] = ['Nombre', (string)($person->GivenName ?? 'N/A')];
            $tableData[] = ['Apellido', (string)($person->FamilyName ?? 'N/A')];
            
            if (isset($person->PreferredName)) {
                $tableData[] = ['Nombre Preferido', (string)$person->PreferredName];
            }
            
            $tableData[] = ['Género', (string)($person->GenderCode ?? 'N/A')];
            
            // Dirección
            if (isset($person->PostalAddress)) {
                $address = $person->PostalAddress;
                $tableData[] = ['Dirección', (string)($address->LineOne ?? 'N/A')];
                $tableData[] = ['Ciudad', (string)($address->CityName ?? 'N/A')];
                $tableData[] = ['Estado', (string)($address->StateOrProvinceCountrySubDivisionID ?? 'N/A')];
                $tableData[] = ['Código Postal', (string)($address->Postcode ?? 'N/A')];
            }
            
            // Teléfonos
            if (isset($person->TelephoneCommunication)) {
                foreach ($person->TelephoneCommunication as $phone) {
                    $tableData[] = [
                        'Teléfono (' . ($phone->UseCode ?? 'N/A') . ')',
                        (string)($phone->CompleteNumber ?? 'N/A')
                    ];
                }
            }
            
            // Email
            if (isset($person->URICommunication)) {
                $tableData[] = ['Email', (string)($person->URICommunication->URIID ?? 'N/A')];
            }
        }

        $this->table(['Campo', 'Valor'], $tableData);
        
        $this->line('');
        $this->info(str_repeat('═', 60));
    }
}