<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Filesystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Actions\ApplyFilesystemMapperAction;
use Kanvas\Filesystem\Actions\CreateFilesystemMapperAction;
use Kanvas\Filesystem\DataTransferObject\FilesystemMapper as FilesystemMapperData;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Actions\CreateProductTypeAction;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes as ProductsTypesDto;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Tests\TestCase;

final class ApplyFilesystemMapperActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testCreatesProductOnlyWhenMapperHasNoLinks(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $mapper = $this->makeProductMapper();

        $entity = new ApplyFilesystemMapperAction(
            $app,
            $company,
            $user,
            $mapper,
            'SFID001',
            $this->samplePropertyRawData('SFID001', 'Test Property One'),
        )->execute();

        $this->assertInstanceOf(Products::class, $entity);
        $this->assertSame('Test Property One', $entity->name);
        $this->assertSame('Test Brand', $entity->description);
        $this->assertNull($entity->get('broker_people_id'));

        $this->assertSame('Negotiations Ongoing', $entity->getAttributeByName('Deal Status')?->value);
        $this->assertSame('Active', $entity->getAttributeByName('Marketing Status')?->value);
        $this->assertSame('Retail', $entity->getAttributeByName('Building Type')?->value);
        $this->assertEquals(12500, $entity->getAttributeByName('Building Size')?->value);
        $this->assertEquals(1.75, $entity->getAttributeByName('Acreage')?->value);
        $this->assertEquals(1998, $entity->getAttributeByName('Year Built')?->value);
        $this->assertSame('Commercial', $entity->getAttributeByName('Zoning')?->value);
        $this->assertSame('Sale', $entity->getAttributeByName('Offering')?->value);
        $this->assertSame('123 Main St', $entity->getAttributeByName('Street')?->value);
        $this->assertSame('East Tawas', $entity->getAttributeByName('City')?->value);
        $this->assertSame('MI', $entity->getAttributeByName('State Province')?->value);
        $this->assertEquals('48730', $entity->getAttributeByName('Zip Code')?->value);
        $this->assertEquals(44.2769, $entity->getAttributeByName('Latitude')?->value);
        $this->assertEquals(-83.4327, $entity->getAttributeByName('Longitude')?->value);
    }

    public function testCreatesLinkedPeopleViaCorrelatedRecords(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $peopleMapper = $this->makePeopleMapper();
        $productMapper = $this->makeProductMapper([
            [
                'mapper_id' => $peopleMapper->id,
                'source_object' => 'Location_Contact__c',
                'match_field' => 'Location__c',
                'link_field' => 'broker_people_id',
            ],
        ]);

        $entity = new ApplyFilesystemMapperAction(
            $app,
            $company,
            $user,
            $productMapper,
            'SFID002',
            $this->samplePropertyRawData('SFID002', 'Test Property Two'),
            [
                $peopleMapper->id => [
                    'Id' => 'SFCONTACT002',
                    'Contact_Name__c' => 'Test Broker Two',
                    'Contact_Email__c' => 'broker2@test.com',
                    'Contact_Phone__c' => '555-0002',
                ],
            ],
        )->execute();

        $this->assertInstanceOf(Products::class, $entity);
        $brokerId = $entity->get('broker_people_id');
        $this->assertNotNull($brokerId);

        $broker = People::find((int) $brokerId);
        $this->assertSame('Test Broker Two', $broker->firstname);
    }

    public function testCreatesLinkedPeopleViaRelatedRecordFetcher(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $peopleMapper = $this->makePeopleMapper();
        $productMapper = $this->makeProductMapper([
            [
                'mapper_id' => $peopleMapper->id,
                'source_object' => 'Location_Contact__c',
                'match_field' => 'Location__c',
                'link_field' => 'broker_people_id',
            ],
        ]);

        $fetcherCalls = [];
        $fetcher = function (string $sourceObject, string $matchField, string $primaryId) use (&$fetcherCalls): ?array {
            $fetcherCalls[] = [$sourceObject, $matchField, $primaryId];

            return [
                'Id' => 'SFCONTACT003',
                'Contact_Name__c' => 'Test Broker Three',
                'Contact_Email__c' => 'broker3@test.com',
                'Contact_Phone__c' => '555-0003',
            ];
        };

        $entity = new ApplyFilesystemMapperAction(
            $app,
            $company,
            $user,
            $productMapper,
            'SFID003',
            $this->samplePropertyRawData('SFID003', 'Test Property Three'),
            [],
            $fetcher,
        )->execute();

        $this->assertCount(1, $fetcherCalls);
        $this->assertSame(['Location_Contact__c', 'Location__c', 'SFID003'], $fetcherCalls[0]);

        $brokerId = $entity->get('broker_people_id');
        $this->assertNotNull($brokerId);

        $broker = People::find((int) $brokerId);
        $this->assertSame('Test Broker Three', $broker->firstname);
    }

    /**
     * Mirrors the real field set `ImportSalesforcePropertiesCommand` pulls from GAGroup's
     * `Location__c` — same 18 fields, so this test exercises the mapper against data shaped like
     * what actually comes back from the sandbox, not a 2-field stub.
     */
    private function samplePropertyRawData(string $salesforceId, string $name): array
    {
        return [
            'Id' => $salesforceId,
            'Name' => $name,
            'Property_Name__c' => $name,
            'Deal_Status__c' => 'Negotiations Ongoing',
            'Marketing_Status__c' => 'Active',
            'Street__c' => '123 Main St',
            'City__c' => 'East Tawas',
            'State_Province__c' => 'MI',
            'Zip_Code__c' => '48730',
            'Brand__c' => 'Test Brand',
            'Ask_Deal_Type__c' => 'Sale',
            'Location_Type__c' => 'Retail',
            'Gross_SF__c' => '12500',
            'Property_Acreage__c' => '1.75',
            'Year_Built__c' => '1998',
            'Zoning__c' => 'Commercial',
            'Latitude__c' => '44.2769',
            'Longitude__c' => '-83.4327',
        ];
    }

    private function makeProductMapper(array $links = []): FilesystemMapper
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $company = $user->getCurrentCompany();

        $productType = new CreateProductTypeAction(
            new ProductsTypesDto(
                $company,
                $user,
                'ApplyMapper Test Type ' . uniqid(),
                'test',
                1,
                true,
            ),
            $user,
        )->execute();

        $systemModule = SystemModulesRepository::getByModelName(Products::class, $app);

        $dto = FilesystemMapperData::viaRequest(
            $app,
            $branch,
            $user,
            $systemModule,
            [
                'name' => 'ApplyMapper Test Product Mapper ' . uniqid(),
                'system_module_id' => $systemModule->getId(),
                'has_header' => false,
                'mapping' => [
                    'name' => 'Property_Name__c',
                    'description' => 'Brand__c',
                    'sku' => 'Id',
                    'attributes' => [
                        ['Deal Status' => 'Deal_Status__c'],
                        ['Marketing Status' => 'Marketing_Status__c'],
                        ['Building Type' => 'Location_Type__c'],
                        ['Building Size' => 'Gross_SF__c'],
                        ['Acreage' => 'Property_Acreage__c'],
                        ['Year Built' => 'Year_Built__c'],
                        ['Zoning' => 'Zoning__c'],
                        ['Offering' => 'Ask_Deal_Type__c'],
                        ['Street' => 'Street__c'],
                        ['City' => 'City__c'],
                        ['State Province' => 'State_Province__c'],
                        ['Zip Code' => 'Zip_Code__c'],
                        ['Latitude' => 'Latitude__c'],
                        ['Longitude' => 'Longitude__c'],
                    ],
                ],
                'configuration' => [
                    'product_type_id' => $productType->getId(),
                    'links' => $links,
                ],
            ],
        );

        return new CreateFilesystemMapperAction($dto)->execute();
    }

    private function makePeopleMapper(): FilesystemMapper
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $branch = $user->getCurrentBranch();

        $systemModule = SystemModulesRepository::getByModelName(People::class, $app);

        $dto = FilesystemMapperData::viaRequest(
            $app,
            $branch,
            $user,
            $systemModule,
            [
                'name' => 'ApplyMapper Test People Mapper ' . uniqid(),
                'system_module_id' => $systemModule->getId(),
                'has_header' => false,
                'mapping' => [
                    'firstname' => 'Contact_Name__c',
                    'email' => 'Contact_Email__c',
                    'phone' => 'Contact_Phone__c',
                ],
            ],
        );

        return new CreateFilesystemMapperAction($dto)->execute();
    }
}
