<?php

declare(strict_types=1);

namespace Tests\GraphQL\Event;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Actions\BuildScheduleResponseAction;
use Kanvas\Event\Events\Actions\ResourceScheduleValidator;
use Kanvas\Event\Events\Actions\SetResourceScheduleAction;
use Kanvas\Event\Events\Enums\ScheduleTypeEnum;
use Kanvas\Event\Events\Models\ScheduleException;
use Kanvas\Event\Events\Models\ScheduleRules;
use Kanvas\Event\Support\Setup;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class ResourceScheduleTest extends TestCase
{
    use InventoryCases;

    protected $region;
    protected $company;
    protected $user;
    protected $apps;
    protected $product;
    protected $variantId;

    public function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
        $this->region = Regions::getDefault($this->company, $this->apps);

        $warehouseResponse = $this->createWarehouses((string) $this->region->getId())->json()['data']['createWarehouse'];
        $channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $productResponse = $this->createProduct(attributes: [
            ['name' => 'event_slot', 'value' => 100],
        ])->json()['data']['createProduct'];

        $this->product = Products::find($productResponse['id']);
        $this->variantId = $this->product->variants()->first()->id;

        $this->addVariantToChannel(
            variantId: (string) $this->variantId,
            channelId: $channelResponse['id'],
            warehouseData: ['id' => $warehouseResponse['id']]
        );

        $this->addVariantToWarehouse(
            variantId: (string) $this->variantId,
            warehouseId: $warehouseResponse['id'],
            amount: 100
        );

        $setup = new Setup($this->apps, $this->user, $this->company);
        $setup->run();
    }

    public function testSetWeeklySchedule(): void
    {
        Queue::fake();

        $days = [
            'monday' => ['active' => true, 'open' => '08:00', 'close' => '17:00'],
            'tuesday' => ['active' => true, 'open' => '08:00', 'close' => '17:00'],
            'wednesday' => ['active' => true, 'open' => '08:00', 'close' => '17:00'],
            'thursday' => ['active' => false, 'open' => null, 'close' => null],
            'friday' => ['active' => true, 'open' => '09:00', 'close' => '18:00'],
            'saturday' => ['active' => false, 'open' => null, 'close' => null],
            'sunday' => ['active' => false, 'open' => null, 'close' => null],
        ];

        $rules = new SetResourceScheduleAction(
            resource: $this->product,
            app: $this->apps,
            company: $this->company,
            days: $days,
            scheduleType: ScheduleTypeEnum::WEEKLY,
        )->execute();

        $this->assertCount(4, $rules);

        foreach ($rules as $rule) {
            $this->assertEquals($this->product->getId(), $rule->resources_id);
            $this->assertEquals($this->product->getMorphClass(), $rule->resources_type);
            $this->assertStringContains('FREQ=WEEKLY', $rule->rrule);
            $this->assertEquals('operation_days', $rule->metadata['created_from']);
            $this->assertEquals('weekly', $rule->metadata['schedule_type']);
        }

        $dayNames = array_map(fn ($r) => $r->metadata['operation_day'], $rules);
        $this->assertEqualsCanonicalizing(['monday', 'tuesday', 'wednesday', 'friday'], $dayNames);
    }

    public function testSetMonthlySchedule(): void
    {
        Queue::fake();

        $days = [
            'monday' => ['active' => true, 'open' => '10:00', 'close' => '16:00'],
            'tuesday' => ['active' => false, 'open' => null, 'close' => null],
            'wednesday' => ['active' => false, 'open' => null, 'close' => null],
            'thursday' => ['active' => false, 'open' => null, 'close' => null],
            'friday' => ['active' => true, 'open' => '10:00', 'close' => '16:00'],
            'saturday' => ['active' => false, 'open' => null, 'close' => null],
            'sunday' => ['active' => false, 'open' => null, 'close' => null],
        ];

        $rules = new SetResourceScheduleAction(
            resource: $this->product,
            app: $this->apps,
            company: $this->company,
            days: $days,
            scheduleType: ScheduleTypeEnum::MONTHLY,
        )->execute();

        $this->assertCount(2, $rules);

        foreach ($rules as $rule) {
            $this->assertStringContains('FREQ=MONTHLY', $rule->rrule);
            $this->assertEquals('monthly', $rule->metadata['schedule_type']);
        }
    }

    public function testIsResourceOpenDuringHours(): void
    {
        Queue::fake();

        $this->createWeeklySchedule($this->product, [
            'monday' => ['active' => true, 'open' => '08:00', 'close' => '18:00'],
        ]);

        $monday = Carbon::parse('next monday 10:00');

        $validator = new ResourceScheduleValidator($this->product, $this->apps);
        $this->assertTrue($validator->isOpen($monday));
    }

    public function testIsResourceClosedOutsideHours(): void
    {
        Queue::fake();

        $this->createWeeklySchedule($this->product, [
            'monday' => ['active' => true, 'open' => '08:00', 'close' => '18:00'],
        ]);

        $mondayLate = Carbon::parse('next monday 20:00');

        $validator = new ResourceScheduleValidator($this->product, $this->apps);
        $this->assertFalse($validator->isOpen($mondayLate));
    }

    public function testIsResourceClosedOnInactiveDay(): void
    {
        Queue::fake();

        $this->createWeeklySchedule($this->product, [
            'monday' => ['active' => true, 'open' => '08:00', 'close' => '18:00'],
            'tuesday' => ['active' => false, 'open' => null, 'close' => null],
        ]);

        $tuesday = Carbon::parse('next tuesday 10:00');

        $validator = new ResourceScheduleValidator($this->product, $this->apps);
        $this->assertFalse($validator->isOpen($tuesday));
    }

    public function testBlackoutExceptionClosesResource(): void
    {
        Queue::fake();

        $this->createWeeklySchedule($this->product, [
            'monday' => ['active' => true, 'open' => '08:00', 'close' => '18:00'],
        ]);

        $monday = Carbon::parse('next monday 10:00');

        ScheduleException::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->product->getId(),
            'resources_type' => $this->product->getMorphClass(),
            'kind' => 'blackout',
            'window_start' => $monday->copy()->startOfDay(),
            'window_end' => $monday->copy()->endOfDay(),
            'reason' => 'Holiday',
        ]);

        $validator = new ResourceScheduleValidator($this->product, $this->apps);
        $this->assertFalse($validator->isOpen($monday));
    }

    public function testExtraOpenExceptionOpensResource(): void
    {
        Queue::fake();

        $this->createWeeklySchedule($this->product, [
            'monday' => ['active' => true, 'open' => '08:00', 'close' => '18:00'],
            'sunday' => ['active' => false, 'open' => null, 'close' => null],
        ]);

        $sunday = Carbon::parse('next sunday 10:00');

        ScheduleException::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->product->getId(),
            'resources_type' => $this->product->getMorphClass(),
            'kind' => 'extra_open',
            'window_start' => $sunday->copy()->startOfDay(),
            'window_end' => $sunday->copy()->endOfDay(),
            'reason' => 'Special event',
        ]);

        $validator = new ResourceScheduleValidator($this->product, $this->apps);
        $this->assertTrue($validator->isOpen($sunday));
    }

    public function testNoScheduleMeansAlwaysOpen(): void
    {
        $validator = new ResourceScheduleValidator($this->product, $this->apps);

        $this->assertFalse($validator->hasScheduleConfigured());
        $this->assertTrue($validator->isOpen(Carbon::now()));
    }

    public function testScheduleTypeChangeClearsOldRules(): void
    {
        Queue::fake();

        $weeklyDays = [
            'monday' => ['active' => true, 'open' => '08:00', 'close' => '17:00'],
            'friday' => ['active' => true, 'open' => '08:00', 'close' => '17:00'],
        ];

        $weeklyRules = new SetResourceScheduleAction(
            resource: $this->product,
            app: $this->apps,
            company: $this->company,
            days: $weeklyDays,
            scheduleType: ScheduleTypeEnum::WEEKLY,
        )->execute();

        $this->assertCount(2, $weeklyRules);

        $monthlyDays = [
            'wednesday' => ['active' => true, 'open' => '10:00', 'close' => '16:00'],
        ];

        $monthlyRules = new SetResourceScheduleAction(
            resource: $this->product,
            app: $this->apps,
            company: $this->company,
            days: $monthlyDays,
            scheduleType: ScheduleTypeEnum::MONTHLY,
        )->execute();

        $this->assertCount(1, $monthlyRules);

        $remainingRules = ScheduleRules::where('resources_id', $this->product->getId())
            ->where('resources_type', $this->product->getMorphClass())
            ->where('apps_id', $this->apps->getId())
            ->whereJsonContains('metadata->created_from', 'operation_days')
            ->where('is_deleted', false)
            ->count();

        $this->assertEquals(1, $remainingRules);
    }

    public function testBuildScheduleResponseAction(): void
    {
        Queue::fake();

        $this->createWeeklySchedule($this->product, [
            'monday' => ['active' => true, 'open' => '08:00', 'close' => '18:00'],
            'wednesday' => ['active' => true, 'open' => '09:00', 'close' => '17:00'],
        ]);

        $response = new BuildScheduleResponseAction($this->product, $this->apps)->execute();

        $this->assertTrue($response['is_configured']);
        $this->assertEquals('weekly', $response['schedule_type']);
        $this->assertEquals(2, $response['days_count']);
        $this->assertCount(7, $response['days']);
        $this->assertIsArray($response['rules']);
        $this->assertIsArray($response['exceptions']);
    }

    public function testVariantInheritsProductSchedule(): void
    {
        Queue::fake();

        $this->createWeeklySchedule($this->product, [
            'monday' => ['active' => true, 'open' => '08:00', 'close' => '18:00'],
        ]);

        $variant = $this->product->variants()->first();
        $monday = Carbon::parse('next monday 10:00');

        $validator = new ResourceScheduleValidator($variant, $this->apps);
        $this->assertTrue($validator->isOpen($monday));

        $mondayLate = Carbon::parse('next monday 20:00');
        $this->assertFalse($validator->isOpen($mondayLate));
    }

    private function createWeeklySchedule(Products $product, array $days): array
    {
        $fullDays = [];
        $allDays = [
            'monday',
            'tuesday',
            'wednesday',
            'thursday',
            'friday',
            'saturday',
            'sunday'
        ];

        foreach ($allDays as $day) {
            $fullDays[$day] = $days[$day] ?? ['active' => false, 'open' => null, 'close' => null];
        }

        return new SetResourceScheduleAction(
            resource: $product,
            app: $this->apps,
            company: $this->company,
            days: $fullDays,
            scheduleType: ScheduleTypeEnum::WEEKLY,
        )->execute();
    }

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'"
        );
    }
}
