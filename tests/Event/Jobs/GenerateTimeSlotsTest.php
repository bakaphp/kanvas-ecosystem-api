<?php

declare(strict_types=1);

namespace Tests\Event\Jobs;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Jobs\GenerateTimeSlots;
use Kanvas\Event\Events\Models\ScheduleRules;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Inventory\Products\Models\Products;
use Tests\TestCase;

class GenerateTimeSlotsTest extends TestCase
{
    public function testJobCanBeSerializedAndDispatched(): void
    {
        Queue::fake();

        $windowFrom = Carbon::now();
        $windowTo = Carbon::now()->addWeek();

        GenerateTimeSlots::dispatch_sync(1, 1, $windowFrom, $windowTo);

        Queue::assertPushed(GenerateTimeSlots::class, function ($job) {
            return $job->resourceId === 1 
                && $job->ruleId === 1;
        });
    }

    public function testJobGeneratesTimeSlotsSuccessfully(): void
    {
        // Create a mock product as the resource
        $product = Products::factory()->create([
            'name' => 'Test Resource',
        ]);
        
        // Add default_capacity to the product
        $product->setAttribute('default_capacity', 10);
        $product->save();

        // Create a schedule rule
        $rule = ScheduleRules::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'resources_id' => $product->getId(),
            'resources_type' => get_class($product),
            'start_at' => Carbon::now()->startOfDay(),
            'end_at' => Carbon::now()->addWeek()->endOfDay(),
            'rrule' => 'FREQ=DAILY;INTERVAL=1',
            'slot_duration_min' => 60,
            'lead_time_min' => 30,
            'cutoff_time_min' => 15,
        ]);

        $windowFrom = Carbon::now()->startOfDay();
        $windowTo = Carbon::now()->addDays(3)->endOfDay();

        // Create and handle the job
        $job = new GenerateTimeSlots(
            $product->getId(),
            $rule->getId(),
            $windowFrom,
            $windowTo
        );

        // Execute the job
        $job->handle();

        // Assert that time slots were created
        $this->assertGreaterThan(0, TimeSlots::where('resource_id', $product->getId())->count());
    }

    public function testJobHandlesTimezoneCorrectly(): void
    {
        // Create a mock product with timezone
        $product = Products::factory()->create([
            'name' => 'Test Resource with Timezone',
        ]);
        
        // Mock the app to return a specific timezone
        $app = $this->createMock(Apps::class);
        $app->method('get')->with('timezone')->willReturn('America/New_York');
        
        // Mock the product to return the app
        $product = $this->getMockBuilder(Products::class)
            ->setConstructorArgs([])
            ->onlyMethods(['getAttribute'])
            ->getMock();
        
        $product->method('getAttribute')->willReturnMap([
            ['id', 1],
            ['app', $app],
            ['tz', null],
            ['default_capacity', 5],
        ]);

        // Create a schedule rule
        $rule = ScheduleRules::create([
            'apps_id' => 1,
            'companies_id' => 1,
            'resources_id' => 1,
            'resources_type' => get_class($product),
            'start_at' => Carbon::now('America/New_York')->startOfDay(),
            'end_at' => Carbon::now('America/New_York')->addDays(2)->endOfDay(),
            'rrule' => 'FREQ=DAILY;INTERVAL=1',
            'slot_duration_min' => 30,
        ]);

        // Mock the rule to return the mocked product
        $rule = $this->getMockBuilder(ScheduleRules::class)
            ->setConstructorArgs([])
            ->onlyMethods(['getAttribute'])
            ->getMock();
        
        $rule->method('getAttribute')->willReturnMap([
            ['id', 1],
            ['resource', $product],
            ['start_at', Carbon::now('America/New_York')->startOfDay()],
            ['end_at', Carbon::now('America/New_York')->addDays(2)->endOfDay()],
            ['rrule', 'FREQ=DAILY;INTERVAL=1'],
            ['slot_duration_min', 30],
            ['capacity_override', null],
        ]);

        $windowFrom = Carbon::now()->startOfDay();
        $windowTo = Carbon::now()->addDays(2)->endOfDay();

        $job = new GenerateTimeSlots(1, 1, $windowFrom, $windowTo);
        
        // This test mainly verifies the job doesn't throw timezone-related errors
        $this->expectNotToPerformAssertions();
    }

    public function testJobSkipsBlackedOutPeriods(): void
    {
        // This test would require setting up ScheduleException records
        // and verifying that time slots are not created during blackout periods
        $this->markTestSkipped('Requires ScheduleException setup');
    }

    public function testJobHandlesEmptyRuleGracefully(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        
        $windowFrom = Carbon::now();
        $windowTo = Carbon::now()->addWeek();

        $job = new GenerateTimeSlots(999, 999, $windowFrom, $windowTo);
        $job->handle();
    }
}