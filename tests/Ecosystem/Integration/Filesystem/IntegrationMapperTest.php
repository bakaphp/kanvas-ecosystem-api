<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Filesystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Filesystem\Actions\CreateFilesystemMapperAction;
use Kanvas\Filesystem\Actions\ImportDataFromFilesystemAction;
use Kanvas\Filesystem\DataTransferObject\FilesystemMapper;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as DataTransferObjectPeople;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Actions\CreateRegionAction;
use Kanvas\Inventory\Regions\DataTransferObject\Region;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

final class IntegrationMapperTest extends TestCase
{
    public function testImportDataFromFilesystemAction(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $mapper = [
            'input' => [
                'name' => 'events trb 5',
                'file_header' => [
                    'NAME',
                    'TITLE',
                    'COMPANY',
                    'EMAIL',
                    'TOPIC 1',
                    'TOPIC 2',
                    'LOCATION',
                    'DINNER',
                    'LINKEDIN',
                    'TRB SUBSCRIBER? Y / N',
                ],
                'system_module_id' => 31,
                'mapping' => [
                    'name' => 'NAME',
                    "firstname" => "NAME",
                    'organization' => 'COMPANY',
                    'contacts' => [
                        [
                            'value' => 'EMAIL',
                            'weight' => '_0',
                            'contacts_types_id' => '_1',
                        ],
                        [
                            'value' => 'LINKEDIN',
                            'weight' => '_0',
                            'contacts_types_id' => '_5',
                        ],
                    ],
                    'custom_fields' => [
                        [
                            'name' => '_title',
                            'value' => 'TITLE',
                        ],
                        [
                            'name' => '_company',
                            'value' => 'COMPANY',
                        ],
                        [
                            'name' => '_location',
                            'value' => 'LOCATION',
                        ],
                    ],
                    'tags' => [[
                        'name' => 'TRB SUBSCRIBER? Y / N',
                    ],
                    ],
                ],
            ],
        ];

        $filesystemMapperName = 'Products' . uniqid();
        $dto = new FilesystemMapper(
            $app,
            $user->getCurrentBranch(),
            $user,
            SystemModulesRepository::getByModelName(People::class),
            $filesystemMapperName,
            [],
            $mapper,
        );
        $filesystemMapper = (new CreateFilesystemMapperAction($dto))->execute();

        $regionDto = new Region(
            $user->getCurrentCompany(),
            $app,
            $user,
            Currencies::getById(1),
            'Region Name',
            'Region Short Slug',
            null,
            1,
        );
        $region = (new CreateRegionAction($regionDto, $user))->execute();
        $warehouseDto = new Warehouses(
            $user->getCurrentCompany(),
            $app,
            $user,
            $region,
            'Warehouse Name',
            'Warehouse Location',
            true,
            true,
        );
        $warehouse = (new CreateWarehouseAction($warehouseDto, $user))->execute();
        $values = [
            'NAME' => 'Ryan Heafy',
            'TITLE' => 'Co-Founder & COO',
            'COMPANY' => '6am City',
            'EMAIL' => 'rheafy@6amcity.com',
            'TOPIC 1' => 'Local',
            'TOPIC 2' => null,
            'LOCATION' => 'Charlotte',
            'DINNER' => null,
            'LINKEDIN' => 'https://www.linkedin.com/in/ryanheafy/',
            'TRB SUBSCRIBER? Y / N' => 'Y',
            ];

        $importDataFromFilesystemAction = new ImportDataFromFilesystemAction(new FilesystemImports());
        $customerData = $importDataFromFilesystemAction->mapper($filesystemMapper->mapping, $values);
            $customerData = $customerData['input']['mapping'];
        $people = DataTransferObjectPeople::from([
            'app' => $app,
            'branch' => $user->getCurrentCompany()->branch,
            'user' => $user,
            'firstname' => $customerData['firstname'],
            'middlename' => $customerData['middlename'] ?? null,
            'lastname' => $customerData['lastname'] ?? null,
            'contacts' => Contact::collect($customerData['contacts'] ?? [], DataCollection::class),
            'address' => Address::collect($customerData['address'] ?? [], DataCollection::class),
            'dob' => $customerData['dob'] ?? null,
            'facebook_contact_id' => $customerData['facebook_contact_id'] ?? null,
            'google_contact_id' => $customerData['google_contact_id'] ?? null,
            'apple_contact_id' => $customerData['apple_contact_id'] ?? null,
            'linkedin_contact_id' => $customerData['linkedin_contact_id'] ?? null,
            'custom_fields' => $customerData['custom_fields'] ?? [],
            'tags' => $customerData['tags'] ?? [],
            'organization' => $customerData['organization'] ?? null,
            'created_at' => $customerData['created_at'] ?? null,
        ]);

        if ($people->contacts->count()) {
            foreach ($people->contacts as $contact) {
                $customer = PeoplesRepository::getByValue($contact->value, $company, $app);
                if ($customer) {
                    $people->id = $customer->id;

                    break;
                }
            }
        }

        $peopleSync = new CreatePeopleAction($people);
        $peopleModel = $peopleSync->execute();

        print_R($peopleModel); die();
        //print_r($dataMapper); die();
        $productDto = ProductImporter::from($dataMapper);

        $productImporter = new ProductImporterAction(
            $productDto,
            $user->getCurrentCompany(),
            $user,
            $region
        );

        $this->assertInstanceOf(Products::class, $productImporter->execute());
    }
}
