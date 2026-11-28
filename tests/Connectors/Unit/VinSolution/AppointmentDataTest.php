<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\VinSolution;

use Carbon\Carbon;
use Kanvas\Connectors\VinSolution\Appointments\AppointmentData;
use PHPUnit\Framework\TestCase;

final class AppointmentDataTest extends TestCase
{
    public function testPayloadMatchesTheCarDashboardWireShape(): void
    {
        $data = new AppointmentData(
            startsAt: Carbon::parse('2026-12-01T16:00:00Z'),
            endsAt: Carbon::parse('2026-12-01T16:30:00Z'),
            dealerId: '50010',
            leadId: '700200300',
            customerId: '800200300',
            assignedUserId: '60020',
        );

        $this->assertSame(
            [
                'startDateUtc' => '2026-12-01T16:00:00.000',
                'endDateUtc' => '2026-12-01T16:30:00.000',
                'purpose' => '1',
                'dealerId' => '50010',
                'leadId' => '700200300',
                'customerId' => '800200300',
                'assignedUserId' => '60020',
                'location' => ['type' => 'DEALER_ADDRESS'],
                'description' => 'Sales appointment',
            ],
            $data->toApiPayload()
        );
    }

    public function testTimesAreNormalizedToUtcWithMillisecondPrecision(): void
    {
        // 10:00 Central -> 16:00 UTC: the caller's zone must not leak into the wire format.
        $data = new AppointmentData(
            startsAt: Carbon::parse('2026-12-01 10:00:00', 'America/Chicago'),
            endsAt: Carbon::parse('2026-12-01 10:30:00', 'America/Chicago'),
            dealerId: '50010',
            leadId: '1',
            customerId: '2',
            assignedUserId: '3',
        );

        $payload = $data->toApiPayload();

        $this->assertSame('2026-12-01T16:00:00.000', $payload['startDateUtc']);
        $this->assertSame('2026-12-01T16:30:00.000', $payload['endDateUtc']);
    }
}
