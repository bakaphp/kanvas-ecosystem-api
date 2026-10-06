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
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertSame('mall', $attributes['type']);
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
        $this->assertSame(['card', 'cash'], $warehouseRow->config['payment_methods']);
        $this->assertSame(['lighting', 'cameras', 'security_staff', 'electronic_gate'], $warehouseRow->config['infrastructure']);
        $this->assertSame(8, $warehouseRow->config['camera_count']);

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
        $this->assertStringStartsWith('2026-09-16T09:30:00', $contract['accepted_at']);
        $this->assertArrayNotHasKey('ip', $contract, 'Product attributes are public and indexed; the IP stays on the Lead');
    }

    public function testABandClosingAfterMidnightSpillsIntoTheNextDay(): void
    {
        $lead = $this->approvedApplication([
            Field::SCHEDULE->value => [
                'weekdays' => [['open' => '07:00', 'close' => '22:00']],
                'saturday' => [['open' => '20:00', 'close' => '02:00']],
                'sunday_holidays' => [],
            ],
            Field::CLOSURES->value => [],
        ]);

        $product = new PublishParkingApplicationAction($lead)->execute();
        $variant = $product->variants()->firstOrFail();

        $periods = ScheduleRules::query()
            ->where('resources_type', $variant->getMorphClass())
            ->where('resources_id', $variant->getId())
            ->get()
            ->mapWithKeys(fn (ScheduleRules $rule) => [$rule->metadata['operation_day'] => $rule->metadata['periods']]);

        $this->assertSame([['open' => '20:00', 'close' => '23:59']], $periods['saturday']);
        $this->assertSame([['open' => '00:00', 'close' => '02:00']], $periods['sunday']);
        $this->assertSame([['open' => '07:00', 'close' => '22:00']], $periods['monday']);
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

    public function testAnInvalidOptionalValueIsIgnoredAndTheParkingStillPublishes(): void
    {
        $lead = $this->approvedApplication([
            Field::INFRASTRUCTURE->value => ['Garita de seguridad'],
            Field::CAMERA_COUNT->value => 'muchas',
        ]);

        $publish = new PublishParkingApplicationAction($lead);
        $product = $publish->execute();

        $this->assertSame((string) $product->getId(), (string) Field::PRODUCT_ID->readFrom($lead->fresh()));
        $this->assertEqualsCanonicalizing(
            [Field::INFRASTRUCTURE->value, Field::CAMERA_COUNT->value],
            array_keys($publish->ignoredFields())
        );

        $config = $product->variants()->firstOrFail()->variantWarehouses()->firstOrFail()->config;
        $this->assertSame([], $config['infrastructure']);
    }

    public function testWithoutAScheduleTheParkingPublishesWithNoOpeningHoursForTheOwnerToSetLater(): void
    {
        $lead = $this->approvedApplication([
            Field::IS_24_7->value => false,
            Field::SCHEDULE->value => null,
            Field::CLOSURES->value => null,
        ]);

        $product = new PublishParkingApplicationAction($lead)->execute();
        $variant = $product->variants()->firstOrFail();

        $ruleDays = ScheduleRules::query()
            ->where('resources_type', $variant->getMorphClass())
            ->where('resources_id', $variant->getId())
            ->count();
        $this->assertSame(0, $ruleDays);

        $attributes = $product->attributeValues->mapWithKeys(fn ($av) => [$av->attribute->slug => $av->value]);
        $this->assertArrayNotHasKey('parking-hours', $attributes);
    }

    #[DataProvider('fieldsTheWizardRequires')]
    public function testAFieldTheWizardRequiresBlocksPublicationWhenMissing(Field $field): void
    {
        $lead = $this->approvedApplication([$field->value => null]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($field->value);

        new PublishParkingApplicationAction($lead)->execute();
    }

    public static function fieldsTheWizardRequires(): array
    {
        return [
            'parking type' => [Field::PARKING_TYPE],
            'structure' => [Field::STRUCTURE],
            'province' => [Field::PROVINCE],
        ];
    }

    public function testAZeroHourlyRateBlocksPublication(): void
    {
        $lead = $this->approvedApplication([Field::RATE_HOURLY->value => 0]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(Field::RATE_HOURLY->value);

        new PublishParkingApplicationAction($lead)->execute();
    }

    public function testFewerThanFourPhotosBlocksPublication(): void
    {
        $lead = $this->parkingApplication($this->kanvasApp, $this->company, $this->fixtureFields());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('photos');

        new PublishParkingApplicationAction($lead->fresh())->execute();
    }

    public function testAnInvalidRequiredValueFailsBeforeAnythingIsWritten(): void
    {
        $lead = $this->approvedApplication([Field::CAPACITY_TOTAL->value => 'muchos']);

        try {
            new PublishParkingApplicationAction($lead)->execute();
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(Field::CAPACITY_TOTAL->value, $e->getMessage());
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
        $lead = $this->approvedApplication();
        CorporateField::COMPANY_ID->writeTo($lead, '0');

        $this->expectException(ModelNotFoundException::class);

        new PublishParkingApplicationAction($lead)->execute();
    }

    public function testProvisionsTheWarehouseWhenOnboardingHasNotLandedYet(): void
    {
        $lead = $this->approvedApplication();

        Warehouses::query()
            ->fromApp($this->kanvasApp)
            ->fromCompany($this->company)
            ->update(['is_deleted' => 1]);

        $this->assertNull(
            Warehouses::query()->fromApp($this->kanvasApp)->fromCompany($this->company)->notDeleted()->first()
        );

        $product = new PublishParkingApplicationAction($lead)->execute();

        $warehouseRow = $product->variants()->firstOrFail()->variantWarehouses()->firstOrFail();
        $this->assertSame($this->company->getId(), (int) $warehouseRow->warehouse->companies_id);
        $this->assertSame(
            ParkingApplicationStatusEnum::PUBLISHED->value,
            Field::STATUS->readFrom($lead->fresh())
        );
    }

    private function approvedApplication(array $overrides = []): Lead
    {
        $lead = $this->parkingApplication($this->kanvasApp, $this->company, array_merge($this->fixtureFields(), $overrides));

        $this->attachFixturePhotos($lead);

        return $lead->fresh();
    }
}
