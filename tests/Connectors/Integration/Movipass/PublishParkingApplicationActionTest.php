<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as CorporateField;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Actions\PublishParkingApplicationAction;
use Kanvas\Connectors\Movipass\Enums\ParkingApplicationContractSettingEnum as Contract;
use Kanvas\Connectors\Movipass\Enums\ParkingApplicationFieldEnum as Field;
use Kanvas\Connectors\Movipass\Enums\ParkingApplicationStatusEnum;
use Kanvas\Event\Events\Models\ScheduleException;
use Kanvas\Event\Events\Models\ScheduleRules;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadAttempt;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Tests\TestCase;

final class PublishParkingApplicationActionTest extends TestCase
{
    use DatabaseTransactions;
    use LoadsParkingApplicationFixture;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'inventory', 'event'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasApp->del(Contract::CURRENT_VERSION->value);
        $this->company = Auth::user()->getCurrentCompany();

        new InventorySetup($this->kanvasApp, Auth::user(), $this->company)->run();
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->del(Contract::CURRENT_VERSION->value);

        parent::tearDown();
    }

    public function testPublishesProductVariantWarehouseScheduleAndPhotos(): void
    {
        $lead = $this->approvedApplication();

        $product = new PublishParkingApplicationAction($lead)->execute();

        $this->assertSame('Parqueo Plaza Central', $product->name);
        $this->assertSame(PublishParkingApplicationAction::PRODUCT_TYPE_SLUG, $product->productsTypes->slug);
        $this->assertSame($this->company->getId(), (int) $product->companies_id);
        $this->assertTrue((bool) $product->is_published);

        $attributes = $product->attributeValues->mapWithKeys(fn ($av) => [$av->attribute->slug => $av->value]);
        $this->assertEquals(['lat' => 18.4718, 'long' => -69.9394], $attributes['coordinates']);
        $this->assertEquals(['open' => '07:00', 'close' => '22:00'], $attributes['parking-hours']);
        $this->assertSame('commercial', $attributes['type']);
        $this->assertSame(120, $attributes['capacity']['totalParkingSpaces']);

        $variant = $product->variants()->firstOrFail();
        $this->assertSame('parking-' . $lead->uuid, $variant->sku);

        $warehouseRow = $variant->variantWarehouses()->firstOrFail();
        $this->assertSame(120.0, (float) $warehouseRow->quantity);
        $this->assertSame(120.0, (float) $warehouseRow->max_capacity);
        $this->assertSame(100.0, (float) $warehouseRow->price);
        $this->assertSame(18.4718, (float) $warehouseRow->latitude);
        $this->assertSame(-69.9394, (float) $warehouseRow->longitude);
        $this->assertSame(500.0, (float) $warehouseRow->config['rates']['overnight']);
        $this->assertSame(['movipass', 'cash'], $warehouseRow->config['payment_methods']);
        $this->assertSame(8, $warehouseRow->config['infrastructure']['camera_count']);

        $files = $product->getFiles();
        $this->assertCount(4, $files);
        $this->assertSame('entrada.jpg', $files[0]['field_name']);
        $this->assertSame('acceso.jpg', $files[3]['field_name']);

        $rules = ScheduleRules::query()
            ->where('resources_type', $variant->getMorphClass())
            ->where('resources_id', $variant->getId())
            ->get();
        $this->assertCount(7, $rules, 'Weekdays, Saturday and Sunday all carry a band in the fixture');

        $closure = ScheduleException::query()
            ->where('resources_type', $variant->getMorphClass())
            ->where('resources_id', $variant->getId())
            ->firstOrFail();
        $this->assertSame('blackout', $closure->kind);
        $this->assertSame('2026-12-25', $closure->window_start->toDateString());
        $this->assertSame('Navidad', $closure->reason);

        $fresh = $lead->fresh();
        $this->assertSame(ParkingApplicationStatusEnum::PUBLISHED->value, Field::STATUS->readFrom($fresh));
        $this->assertSame((string) $product->getId(), (string) Field::PRODUCT_ID->readFrom($fresh));
    }

    public function testStampsTheContractAcceptanceFromTheReceiverRecord(): void
    {
        $this->kanvasApp->set(Contract::CURRENT_VERSION->value, '2026-09');
        $lead = $this->approvedApplication();
        LeadAttempt::create([
            'companies_id' => $lead->companies_id,
            'apps_id' => $lead->apps_id,
            'leads_id' => $lead->getId(),
            'header' => [],
            'request' => [],
            'ip' => '190.166.1.20',
            'source' => 'test',
            'public_key' => '',
            'processed' => 1,
            'created_at' => '2026-09-16 09:30:00',
        ]);

        $product = new PublishParkingApplicationAction($lead->fresh())->execute();

        $fresh = $lead->fresh();
        $this->assertSame('2026-09', Field::CONTRACT_VERSION->readFrom($fresh));
        $this->assertSame('190.166.1.20', Field::CONTRACT_ACCEPTANCE_IP->readFrom($fresh));
        $this->assertStringStartsWith('2026-09-16T09:30:00', (string) Field::CONTRACT_ACCEPTED_AT->readFrom($fresh));

        $contract = $product->attributeValues->first(fn ($av) => $av->attribute->slug === 'contract')->value;
        $this->assertSame('2026-09', $contract['version']);
        $this->assertSame('190.166.1.20', $contract['ip']);
    }

    public function testAnUnacceptedContractBlocksPublication(): void
    {
        $lead = $this->approvedApplication([Field::CONTRACT_ACCEPTED->value => false]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(Field::CONTRACT_ACCEPTED->value);

        new PublishParkingApplicationAction($lead)->execute();
    }

    public function testANewerContractVersionInForceHoldsPublicationUntilReaccepted(): void
    {
        $this->kanvasApp->set(Contract::CURRENT_VERSION->value, '2026-11');
        $lead = $this->approvedApplication([Field::CONTRACT_VERSION->value => '2026-09']);

        try {
            new PublishParkingApplicationAction($lead)->execute();
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('2026-11', $e->getMessage());
            $this->assertStringContainsString('accept it again', $e->getMessage());
        }

        $this->assertNull(Field::PRODUCT_ID->readFrom($lead->fresh()));
    }

    public function testRunningTwiceReturnsTheSameProduct(): void
    {
        $lead = $this->approvedApplication();

        $first = new PublishParkingApplicationAction($lead)->execute();
        $second = new PublishParkingApplicationAction($lead->fresh())->execute();

        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame(1, Products::query()->where('slug', $first->slug)->fromCompany($this->company)->count());
    }

    public function testTwentyFourSevenBecomesSevenFullDays(): void
    {
        $lead = $this->approvedApplication([
            Field::IS_24_7->value => true,
            Field::SCHEDULE->value => null,
            Field::CLOSURES->value => [['type' => 'recurring', 'weekday' => 0]],
        ]);

        $product = new PublishParkingApplicationAction($lead)->execute();
        $variant = $product->variants()->firstOrFail();

        $attributes = $product->attributeValues->mapWithKeys(fn ($av) => [$av->attribute->slug => $av->value]);
        $this->assertEquals(['open' => '00:00', 'close' => '23:59'], $attributes['parking-hours']);

        $ruleDays = ScheduleRules::query()
            ->where('resources_type', $variant->getMorphClass())
            ->where('resources_id', $variant->getId())
            ->count();
        $this->assertSame(6, $ruleDays, 'Sunday is a recurring closure');
    }

    public function testAValueTheWizardLetThroughFailsBeforeAnythingIsWritten(): void
    {
        $lead = $this->approvedApplication([Field::PARKING_TYPE->value => 'Parqueo techado']);

        try {
            new PublishParkingApplicationAction($lead)->execute();
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(Field::PARKING_TYPE->value, $e->getMessage());
        }

        $this->assertSame(0, Products::query()->fromCompany($this->company)->where('name', 'Parqueo Plaza Central')->count());
        $this->assertNull(Field::PRODUCT_ID->readFrom($lead->fresh()));
    }

    public function testCoordinatesPendingValidationBlockPublication(): void
    {
        $lead = $this->approvedApplication([Field::COORDINATES_PENDING_VALIDATION->value => true]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('coordinates');

        new PublishParkingApplicationAction($lead)->execute();
    }

    public function testApplicationWithoutCompanyCannotPublish(): void
    {
        $lead = $this->approvedApplication(companyId: 0);

        $this->expectException(ModelNotFoundException::class);

        new PublishParkingApplicationAction($lead)->execute();
    }

    private function approvedApplication(array $overrides = [], ?int $companyId = null): Lead
    {
        $lead = Lead::factory()
            ->withAppAndCompany($this->kanvasApp->getId(), $this->company->getId())
            ->create(['title' => 'Parqueo Plaza Central']);

        $fields = array_merge($this->fixtureFields(), $overrides);

        foreach ($fields as $key => $value) {
            if ($value !== null) {
                $lead->set($key, $value);
            }
        }

        CorporateField::COMPANY_ID->writeTo($lead, (string) ($companyId ?? $this->company->getId()));

        $lead->addMultipleFilesFromUrl([
            ['url' => 'https://picsum.photos/seed/parqueo-entrada/1200/800.jpg', 'name' => 'entrada.jpg'],
            ['url' => 'https://picsum.photos/seed/parqueo-espacios/1200/800.jpg', 'name' => 'espacios.jpg'],
            ['url' => 'https://picsum.photos/seed/parqueo-seguridad/1200/800.jpg', 'name' => 'seguridad.jpg'],
            ['url' => 'https://picsum.photos/seed/parqueo-acceso/1200/800.jpg', 'name' => 'acceso.jpg'],
        ]);

        return $lead->fresh();
    }
}
