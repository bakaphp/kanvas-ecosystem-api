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

        $mapper = [
            'name' => 'List Number',
            'description' => 'Features',
            'sku' => 'List Number',
            'slug' => 'List Number',
            'regionId' => 'regionId',
            'price' => 'Original List Price',
            'discountPrice' => 'Discount Price',
            'quantity' => 'Quantity',
            'is_published' => 1,
            'files' => 'File URL',
            'productType' => [
                'name' => 'Property Type',
                'description' => 'Property Type',
                'is_published' => 'Is Published',
                'weight' => 'Weight',
            ],
            'customFields' => [],
            'attributes' => [
                [
                    'name' => '_Compensation Comments',
                    'value' => 'Compensation Comments',
                ],
                [
                    'name' => 'Default Value',
                    'value' => '_Default Value',
                ],
            ],
            'variants' => [
                [
                    'name' => 'List Number',
                    'description' => 'Features',
                    'sku' => 'List Number',
                    'price' => 'Original List Price',
                    'discountPrice' => 'Discount Price',
                    'is_published' => 'Status',
                    'slug' => 'List Number',
                    'files' => 'File URL',
                    'warehouse' => [
                        [
                            'id' => 'Warehouse ID',
                            'price' => 'Original List Price',
                            'quantity' => 'Quantity',
                            'sku' => 'List Number',
                            'is_new' => true,
                        ],
                    ],
                ],
            ],
            'categories' => [
                [
                    'name' => 'Style',
                    'code' => 'Style',
                    'is_published' => 'Is Published',
                    'position' => 'Position',
                ],
            ],
            'options' => [], // validate optional params is enable
        ];

        $filesystemMapperName = 'Products' . uniqid();
        $dto = new FilesystemMapper(
            $app,
            $user->getCurrentBranch(),
            $user,
            SystemModulesRepository::getByModelName(Products::class),
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
                    'List Number' => fake()->numerify('LIST-####'),
                    'Features' => fake()->sentence,
                    'regionId' => $region->getId(),
                    'Original List Price' => fake()->randomFloat(2, 100, 1000),
                    'Discount Price' => fake()->randomFloat(2, 50, 900),
                    'Quantity' => fake()->numberBetween(1, 100),
                    'Is Published' => fake()->boolean,
                    'File URL' => fake()->imageUrl . '|' . fake()->imageUrl . '|' . fake()->imageUrl,
                    'File Name' => fake()->word . '.jpg',
                    'Property Type' => fake()->word,
                    'Weight' => fake()->randomFloat(2, 0.5, 5),
                    'customFields' => [],
                    'Status' => fake()->boolean,
                    'Warehouse ID' => $warehouse->getId(),
                    'is_new' => fake()->boolean,
                    'Style' => fake()->word,
                    'Position' => fake()->numberBetween(1, 10),
                    'Compensation Comments' => fake()->sentence,
            ];

        $importDataFromFilesystemAction = new ImportDataFromFilesystemAction(new FilesystemImports());
        $dataMapper = $importDataFromFilesystemAction->mapper($filesystemMapper->mapping, $values);
        $productDto = ProductImporter::from($dataMapper);

        $productImporter = new ProductImporterAction(
            $productDto,
            $user->getCurrentCompany(),
            $user,
            $region
        );

        $this->assertInstanceOf(Products::class, $productImporter->execute());
    }

    public function testImportPeopleDataFromFilesystemAction(): void
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
                    'firstname' => 'NAME',
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
                    'address' => [
                        [
                            'address' => 'LOCATION',
                            'city' => 'LOCATION',
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
                        [
                            'name' => '_trb_subscriber',
                            'value' => 'TRB SUBSCRIBER? Y / N',
                        ],
                    ],
                    'tags' => [[
                        'name' => 'TRB SUBSCRIBER? Y / N',
                    ],
                    ],
                ],
            ],
        ];

        $filesystemMapperName = 'People' . uniqid();
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

        $values = [
            'NAME' => 'Ryan MC',
            'TITLE' => 'Founder',
            'COMPANY' => 'MC City',
            'EMAIL' => 'nada@nodeknot.com',
            'TOPIC 1' => 'Local',
            'TOPIC 2' => null,
            'LOCATION' => 'LA',
            'DINNER' => null,
            'LINKEDIN' => 'https://www.linkedin.com/in/someonebody/',
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
        $this->assertInstanceOf(People::class, $peopleModel);
        $this->assertEquals($peopleModel->firstname, $people->firstname);
        $this->assertEquals($peopleModel->getAllCustomFields(), array_column($people->custom_fields, 'value', 'name'));
        $this->assertEquals($peopleModel->getEmails()->first()->value, $people->contacts->first()->value);
        $this->assertEquals($peopleModel->tags->pluck('name')->toArray(), array_column($people->tags, 'name'));
    }
}
