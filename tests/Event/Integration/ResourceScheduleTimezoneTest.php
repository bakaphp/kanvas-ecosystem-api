<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Actions\CreateScheduleRulesFromOperationDaysAction;
use Kanvas\Event\Events\Jobs\GenerateTimeSlots;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Events\Services\ResourceTimezoneService;
use Kanvas\Event\Support\Setup;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class ResourceScheduleTimezoneTest extends TestCase
{
    use InventoryCases;
    use DatabaseTransactions;

    // Every connection this test writes to has to be listed: DatabaseTransactions only wraps the ones
    // named here, and the slot tables live on `event` while variants live on `inventory`. Left off,
    // those rows commit for good — and the upcoming-slots command sweeps EVERY active rule in the
    // database, so one test's committed rule gets extra slots generated for it by another test
    // running in a parallel process.
    /**
     * `inventory` stays out on purpose: CreateProductAction relies on
     * `DB::connection('inventory')->transaction($cb, 3)` to retry the gap-lock deadlock
     * concurrent product inserts hit, and Laravel only retries a transaction it opened
     * itself — listing the connection here demotes that one to a savepoint and the
     * deadlock escapes as a 500.
     */
    protected $connectionsToTransact = ['mysql', 'ecosystem', 'event'];

    protected $apps;
    protected $user;
    protected $company;
    protected $region;
    protected Variants $variant;

    public function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
        $this->region = Regions::getDefault($this->company, $this->apps);

        $warehouseResponse = $this->graphQLData($this->createWarehouses((string) $this->region->getId()), 'createWarehouse');
        $productResponse = $this->graphQLData($this->createProduct(), 'createProduct');

        $this->variant = Products::find($productResponse['id'])->variants()->first();

        $this->graphQLData(
            $this->addVariantToWarehouse(
                variantId: (string) $this->variant->getId(),
                warehouseId: (string) $warehouseResponse['id'],
                amount: 10
            ),
            'addVariantToWarehouse'
        );

        $this->company->timezone = 'UTC';
        $this->company->saveOrFail();

        new Setup($this->apps, $this->user, $this->company)->run();
    }

    public function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testResolvesTimezoneFromResourceCustomFieldOverCompany(): void
    {
        $this->assertEquals('UTC', ResourceTimezoneService::resolve($this->variant));

        $this->variant->set(ResourceTimezoneService::CUSTOM_FIELD, 'America/Santo_Domingo');

        $this->assertEquals('America/Santo_Domingo', ResourceTimezoneService::resolve($this->variant));
    }

    public function testIgnoresAnInvalidCustomFieldTimezone(): void
    {
        $this->variant->set(ResourceTimezoneService::CUSTOM_FIELD, 'Not/AZone');

        $this->assertEquals('UTC', ResourceTimezoneService::resolve($this->variant));
    }

    public function testOpeningHoursAreStoredAsTheResourceLocalWallClock(): void
    {
        // Wednesday 20:00 in Santo Domingo, so the Thursday rule is still ahead of `now`.
        Carbon::setTestNow(Carbon::parse('2026-08-06 00:00:00', 'UTC'));

        $this->variant->set(ResourceTimezoneService::CUSTOM_FIELD, 'America/Santo_Domingo');

        $rules = new CreateScheduleRulesFromOperationDaysAction(
            resource: $this->variant,
            app: $this->apps,
            company: $this->company,
            operationDays: ['thursday' => ['active' => true, 'open' => '09:00', 'close' => '17:00']],
            slotDurationMinutes: 60,
            capacityOverride: 5,
            generateSlots: false,
            startAt: Carbon::parse('2026-08-06', 'UTC'),
            endAt: Carbon::parse('2026-08-07 03:59:59', 'UTC'),
        )->execute();

        $rule = $rules[0];

        $this->assertStringContainsString('DTSTART;TZID=America/Santo_Domingo:20260806T090000', $rule->rrule);
        $this->assertStringContainsString('DTSTART;TZID=America/Santo_Domingo:20260806T090000', $rule->day_rrule);
        $this->assertEquals('2026-08-06 13:00', $rule->start_at->format('Y-m-d H:i'));

        [$windowFrom, $windowTo] = GenerateTimeSlots::resolveWindow($rule->start_at, $rule->end_at);

        new GenerateTimeSlots(
            $this->variant->getId(),
            $rule->id,
            $windowFrom,
            $windowTo
        )->handle();

        $slots = TimeSlots::where('schedule_rules_id', $rule->id)->orderBy('start_at')->get();

        $this->assertCount(8, $slots, 'One slot per hour from 09:00 to 16:00 venue time');
        $this->assertEquals('2026-08-06 13:00', $slots->first()->start_at->format('Y-m-d H:i'));
        $this->assertEquals('2026-08-06 20:00', $slots->last()->start_at->format('Y-m-d H:i'));
    }
}
