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

    public function testSetScheduleWithMultiplePeriods(): void
    {
        Queue::fake();

        $days = [
            'monday' => [
                'active' => true,
                'periods' => [
                    ['open' => '08:00', 'close' => '12:00'],
                    ['open' => '14:00', 'close' => '18:00'],
                ],
            ],
            'tuesday' => ['active' => false, 'open' => null, 'close' => null],
            'wednesday' => ['active' => false, 'open' => null, 'close' => null],
            'thursday' => ['active' => false, 'open' => null, 'close' => null],
            'friday' => ['active' => false, 'open' => null, 'close' => null],
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

        $this->assertCount(1, $rules);
        $mondayRule = $rules[0];
        $this->assertEquals('monday', $mondayRule->metadata['operation_day']);
        $this->assertArrayHasKey('periods', $mondayRule->metadata);
        $this->assertCount(2, $mondayRule->metadata['periods']);
        $this->assertEquals('08:00', $mondayRule->metadata['periods'][0]['open']);
        $this->assertEquals('12:00', $mondayRule->metadata['periods'][0]['close']);
        $this->assertEquals('14:00', $mondayRule->metadata['periods'][1]['open']);
        $this->assertEquals('18:00', $mondayRule->metadata['periods'][1]['close']);
    }

    public function testValidationWithSplitScheduleClosedDuringRestPeriod(): void
    {
        Queue::fake();

        $days = [
            'monday' => [
                'active' => true,
                'periods' => [
                    ['open' => '08:00', 'close' => '12:00'],
                    ['open' => '14:00', 'close' => '18:00'],
                ],
            ],
            'tuesday' => ['active' => false, 'open' => null, 'close' => null],
            'wednesday' => ['active' => false, 'open' => null, 'close' => null],
            'thursday' => ['active' => false, 'open' => null, 'close' => null],
            'friday' => ['active' => false, 'open' => null, 'close' => null],
            'saturday' => ['active' => false, 'open' => null, 'close' => null],
            'sunday' => ['active' => false, 'open' => null, 'close' => null],
        ];

        new SetResourceScheduleAction(
            resource: $this->product,
            app: $this->apps,
            company: $this->company,
            days: $days,
            scheduleType: ScheduleTypeEnum::WEEKLY,
        )->execute();

        $validator = new ResourceScheduleValidator($this->product, $this->apps);

        // Open during first period
        $mondayMorning = Carbon::parse('next monday 10:00');
        $this->assertTrue($validator->isOpen($mondayMorning));

        // Closed during rest period
        $mondayRestPeriod = Carbon::parse('next monday 13:00');
        $this->assertFalse($validator->isOpen($mondayRestPeriod));

        // Open during second period
        $mondayAfternoon = Carbon::parse('next monday 15:00');
        $this->assertTrue($validator->isOpen($mondayAfternoon));
    }

    public function testBuildScheduleResponseIncludesPeriodsArray(): void
    {
        Queue::fake();

        $days = [
            'monday' => [
                'active' => true,
                'periods' => [
                    ['open' => '08:00', 'close' => '12:00'],
                    ['open' => '14:00', 'close' => '18:00'],
                ],
            ],
            'tuesday' => ['active' => true, 'open' => '09:00', 'close' => '17:00'],
            'wednesday' => ['active' => false, 'open' => null, 'close' => null],
            'thursday' => ['active' => false, 'open' => null, 'close' => null],
            'friday' => ['active' => false, 'open' => null, 'close' => null],
            'saturday' => ['active' => false, 'open' => null, 'close' => null],
            'sunday' => ['active' => false, 'open' => null, 'close' => null],
        ];

        new SetResourceScheduleAction(
            resource: $this->product,
            app: $this->apps,
            company: $this->company,
            days: $days,
            scheduleType: ScheduleTypeEnum::WEEKLY,
        )->execute();

        $response = new BuildScheduleResponseAction($this->product, $this->apps)->execute();

        $this->assertTrue($response['is_configured']);
        $this->assertEquals(2, $response['days_count']);

        // Find Monday in the days array
        $monday = collect($response['days'])->firstWhere('day', 'monday');
        $this->assertNotNull($monday);
        $this->assertTrue($monday['active']);
        $this->assertArrayHasKey('periods', $monday);
        $this->assertCount(2, $monday['periods']);
        $this->assertEquals('08:00', $monday['periods'][0]['open']);
        $this->assertEquals('12:00', $monday['periods'][0]['close']);
        $this->assertEquals('14:00', $monday['periods'][1]['open']);
        $this->assertEquals('18:00', $monday['periods'][1]['close']);
        $this->assertNull($monday['open']); // Multi-period: open/close stay null
        $this->assertNull($monday['close']);

        // Find Tuesday - single period should have both formats (backward compatibility)
        $tuesday = collect($response['days'])->firstWhere('day', 'tuesday');
        $this->assertNotNull($tuesday);
        $this->assertTrue($tuesday['active']);
        $this->assertArrayHasKey('periods', $tuesday);
        $this->assertCount(1, $tuesday['periods']);
        $this->assertEquals('09:00', $tuesday['periods'][0]['open']);
        $this->assertEquals('17:00', $tuesday['periods'][0]['close']);
        $this->assertEquals('09:00', $tuesday['open']); // Backward compatibility
        $this->assertEquals('17:00', $tuesday['close']); // Backward compatibility
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
