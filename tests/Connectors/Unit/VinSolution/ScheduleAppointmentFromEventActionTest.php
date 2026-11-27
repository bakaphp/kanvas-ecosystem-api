<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\VinSolution;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\VinSolution\Actions\ScheduleAppointmentFromEventAction;
use Kanvas\Connectors\VinSolution\Appointments\AppointmentClient;
use Kanvas\Connectors\VinSolution\Appointments\AppointmentData;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Exceptions\ValidationException;
use Mockery;
use Mockery\MockInterface;
use Tests\Connectors\Traits\CreatesVinSolutionAppointmentLead;
use Tests\TestCase;

final class ScheduleAppointmentFromEventActionTest extends TestCase
{
    use CreatesVinSolutionAppointmentLead;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testBooksTheAppointmentFromTheLeadsStoredIdsAndRecordsItOnTheEvent(): void
    {
        $event = $this->mockEvent(existingAppointmentId: null);
        $event->shouldReceive('set')
            ->once()
            ->with(CustomFieldEnum::APPOINTMENT_ID->value, '900200300');

        $client = Mockery::mock(AppointmentClient::class);
        $client->shouldReceive('createSalesAppointment')
            ->once()
            ->with('token-xyz', Mockery::on(function (AppointmentData $data): bool {
                $payload = $data->toApiPayload();

                return $payload['dealerId'] === '50010'
                    && $payload['leadId'] === '700200300'
                    && $payload['customerId'] === '800200300'
                    && $payload['assignedUserId'] === '60020'
                    && $payload['purpose'] === '1'
                    && $payload['startDateUtc'] === '2026-12-01T16:00:00.000'
                    && $payload['endDateUtc'] === '2026-12-01T16:30:00.000';
            }))
            ->andReturn('900200300');

        $id = new ScheduleAppointmentFromEventAction(
            event: $event,
            lead: $this->makeVinSolutionMappedLead(),
            client: $client,
            session: $this->vinSolutionSessionReturning(),
        )->execute();

        $this->assertSame('900200300', $id);
    }

    public function testDoesNotBookTwiceWhenTheEventAlreadyHasAnAppointment(): void
    {
        $event = $this->mockEvent(existingAppointmentId: '900200300');
        $event->shouldNotReceive('set');

        $client = Mockery::mock(AppointmentClient::class);
        $client->shouldNotReceive('createSalesAppointment');

        $id = new ScheduleAppointmentFromEventAction(
            event: $event,
            lead: $this->makeVinSolutionMappedLead(),
            client: $client,
            session: $this->vinSolutionSessionReturning(),
        )->execute();

        $this->assertSame('900200300', $id);
    }

    public function testFailsWhenTheLeadHasNoVinSolutionsLeadId(): void
    {
        $client = Mockery::mock(AppointmentClient::class);
        $client->shouldNotReceive('createSalesAppointment');

        $this->expectException(ValidationException::class);

        new ScheduleAppointmentFromEventAction(
            event: $this->mockEvent(existingAppointmentId: null),
            lead: $this->makeVinSolutionMappedLead(vinLeadId: null),
            client: $client,
            session: $this->vinSolutionSessionReturning(),
        )->execute();
    }

    private function mockEvent(?string $existingAppointmentId): MockInterface&Event
    {
        $event = Mockery::mock(Event::class)->makePartial();
        $event->setRawAttributes(['description' => 'Dealership visit', 'name' => 'Visit']);
        $event->shouldReceive('get')
            ->with(CustomFieldEnum::APPOINTMENT_ID->value)
            ->andReturn($existingAppointmentId);
        $event->shouldReceive('startsAt')->andReturn(Carbon::parse('2026-12-01T16:00:00Z'));
        $event->shouldReceive('latestVersion')->andReturn(null);

        return $event;
    }
}
