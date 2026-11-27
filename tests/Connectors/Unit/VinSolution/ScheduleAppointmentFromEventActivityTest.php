<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\VinSolution;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Workflow\ScheduleAppointmentFromEventActivity;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Workflow\Models\StoredWorkflow;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

final class ScheduleAppointmentFromEventActivityTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testReturnsEarlyWhenCompanyIsNotConfiguredForVinSolution(): void
    {
        $app = app(Apps::class);

        $company = Mockery::mock(Companies::class);
        $company->shouldReceive('get')->once()->with(ConfigurationEnum::COMPANY->value)->andReturn(null);

        $event = Mockery::mock(Event::class)->makePartial();
        $event->company = $company;

        $result = $this->activity()->execute($event, $app, []);

        $this->assertSame(['error' => 'Company not found in VinSolution'], $result);
    }

    private function activity(): ScheduleAppointmentFromEventActivity
    {
        return new ScheduleAppointmentFromEventActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );
    }
}
