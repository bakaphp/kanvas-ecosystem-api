<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\VinSolution;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Connectors\VinSolution\Actions\PullAppointmentsAction;
use Kanvas\Connectors\VinSolution\Appointments\AppointmentClient;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Session\SessionTokenService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Mockery;
use Tests\Connectors\Traits\CreatesVinSolutionAppointmentLead;
use Tests\TestCase;

final class PullAppointmentsActionTest extends TestCase
{
    use CreatesVinSolutionAppointmentLead;
    use DatabaseTransactions;

    private const ACTIVE_APPOINTMENT = [
        'id' => 900200300,
        'status' => 'ACTIVE',
        'startTimeUtc' => '2026-12-01T16:00:00Z',
        'endTimeUtc' => '2026-12-01T16:30:00Z',
        'description' => 'Sales appointment',
        'purpose' => 'SALES_APPOINTMENT',
    ];

    protected $connectionsToTransact = [null, 'crm', 'event'];

    public function testCreatesALinkedKanvasEventForAnActiveAppointmentAndIsIdempotent(): void
    {
        $lead = $this->makeVinSolutionMappedLead();

        $synced = $this->action($lead, [self::ACTIVE_APPOINTMENT])->execute();

        $this->assertArrayHasKey('900200300', $synced);
        $this->assertSame(
            '900200300',
            (string) $synced['900200300']->get(CustomFieldEnum::APPOINTMENT_ID->value)
        );
        $this->assertSame(1, $lead->events()->count());

        $this->action($lead, [self::ACTIVE_APPOINTMENT])->execute();
        $this->assertSame(1, $lead->events()->count());
    }

    public function testDoesNotMirrorAgainAnEventThatCarriesTheLeadOnlyAsItsPrimaryResource(): void
    {
        $lead = $this->makeVinSolutionMappedLead();
        $booked = $this->action($lead, [self::ACTIVE_APPOINTMENT])->execute()['900200300'];

        // What a booking made from Kanvas can look like: the lead on the event row, no event_resources.
        $lead->events()->detach($booked->getId());
        $this->assertSame(0, $lead->events()->count());

        $synced = $this->action($lead, [self::ACTIVE_APPOINTMENT])->execute();

        $this->assertSame($booked->getId(), $synced['900200300']->getId());
    }

    public function testCancelsTheKanvasEventWhenTheAppointmentIsCancelled(): void
    {
        $lead = $this->makeVinSolutionMappedLead();
        $this->action($lead, [self::ACTIVE_APPOINTMENT])->execute();

        $cancelled = ['status' => 'CANCELLED'] + self::ACTIVE_APPOINTMENT;
        $event = $this->action($lead, [$cancelled])->execute()['900200300'];

        $this->assertTrue($event->fresh()->isCancelled());
    }

    public function testReturnsEmptyAndNeverCallsTheApiWhenTheLeadIsNotFullyMapped(): void
    {
        $this->assertNeverCallsTheApi($this->makeVinSolutionMappedLead(contactId: null));
    }

    public function testSkipsWhenNoSessionCookieIsConfigured(): void
    {
        $this->assertNeverCallsTheApi($this->makeVinSolutionMappedLead(withSession: false));
    }

    public function testSkipsWhenTheStoredSessionIsDead(): void
    {
        $session = Mockery::mock(SessionTokenService::class);
        $session->shouldReceive('getAccessToken')
            ->andThrow(new ValidationException('VinSolutions session is not authenticated'));

        $this->assertNeverCallsTheApi($this->makeVinSolutionMappedLead(), $session);
    }

    private function assertNeverCallsTheApi(Lead $lead, ?SessionTokenService $session = null): void
    {
        $client = Mockery::mock(AppointmentClient::class);
        $client->shouldNotReceive('listAppointments');

        $session ??= $this->vinSolutionSessionReturning();

        $this->assertSame([], new PullAppointmentsAction($lead, $client, $session)->execute());
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function action(Lead $lead, array $items): PullAppointmentsAction
    {
        $client = Mockery::mock(AppointmentClient::class);
        $client->shouldReceive('listAppointments')->andReturn($items);

        return new PullAppointmentsAction($lead, $client, $this->vinSolutionSessionReturning());
    }
}
